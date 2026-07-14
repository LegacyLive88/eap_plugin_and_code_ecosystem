// js/eap-office-viewer.js
/**
 * EAP Office File Viewer
 * Handles XLSX (spreadsheet with tabs), DOCX (document viewer), and PPTX (carousel) file previews.
 */

(function($) {
    'use strict';

    var OfficeViewer = {
        initialized: false,
        activeRequest: null,
        currentFileUrl: null,
        currentFileId: null, // Unique ID for each file preview request
        currentSlideIndex: 0,
        totalSlides: 0,
        requestCounter: 0, // Counter to generate unique request IDs

        /**
         * Initialize the office viewer
         */
        init: function() {
            if (this.initialized) {
                return;
            }
            this.initialized = true;
            this.bindEvents();
        },

        /**
         * Bind event listeners
         */
        bindEvents: function() {
            var self = this;

            // Sheet tab clicks for XLSX
            $(document).on('click', '.eap-xlsx-tab', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $tab = $(this);
                var sheetName = $tab.data('sheet');
                if (sheetName) {
                    self.switchSheet(sheetName, $tab);
                }
            });

            // PPTX carousel navigation
            $(document).on('click', '.eap-pptx-nav-prev', function(e) {
                e.preventDefault();
                self.navigateSlide(-1);
            });

            $(document).on('click', '.eap-pptx-nav-next', function(e) {
                e.preventDefault();
                self.navigateSlide(1);
            });

            // PPTX thumbnail clicks
            $(document).on('click', '.eap-pptx-thumb', function(e) {
                e.preventDefault();
                var index = parseInt($(this).data('index'), 10);
                self.goToSlide(index);
            });

            // Keyboard navigation for PPTX
            $(document).on('keydown', '.eap-secure-viewer.is-open', function(e) {
                if (!$('.eap-pptx-viewer').length) {
                    return;
                }
                if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    self.navigateSlide(-1);
                } else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    self.navigateSlide(1);
                }
            });
        },

        /**
         * Check if required libraries are loaded
         */
        checkLibraries: function(type) {
            switch (type) {
                case 'xlsx':
                    return typeof XLSX !== 'undefined' && XLSX.read;
                case 'docx':
                    return typeof mammoth !== 'undefined' && mammoth.convertToHtml;
                case 'pptx':
                    return typeof JSZip !== 'undefined';
                default:
                    return false;
            }
        },

        /**
         * Render XLSX file with sheet tabs
         */
        renderXlsx: function(url, $container, downloadUrl) {
            var self = this;
            
            if (!this.checkLibraries('xlsx')) {
                this.showError($container, 'Excel viewer library not loaded. Please download the file to view.', downloadUrl);
                return;
            }

            if (!$container || !$container.length) {
                console.error('XLSX render: Invalid container');
                return;
            }

            // Cancel any existing request and generate a unique ID for this request
            this.cancelActiveRequest();
            this.requestCounter++;
            var requestId = this.requestCounter;
            this.currentFileUrl = url;
            this.currentFileId = requestId;

            // Clear the container completely before loading new content
            $container
                .removeClass('has-error has-content')
                .empty()
                .html(this.getLoadingHtml('Loading spreadsheet...'));

            // Add cache-busting parameter to URL
            var cacheBustedUrl = this.addCacheBuster(url);

            this.fetchFile(cacheBustedUrl, 'arraybuffer')
                .then(function(data) {
                    // Check if this request is still the current one
                    if (self.currentFileId !== requestId) {
                        console.log('XLSX request superseded, ignoring response for request', requestId);
                        return;
                    }

                    if (!data || data.byteLength === 0) {
                        self.showError($container, 'The file appears to be empty.', downloadUrl);
                        return;
                    }

                    try {
                        var workbookData = data;
                        
                        if (typeof Uint8Array !== 'undefined' && typeof ArrayBuffer !== 'undefined') {
                            if (data instanceof ArrayBuffer) {
                                workbookData = new Uint8Array(data);
                            } else if (
                                !(data instanceof Uint8Array) &&
                                data &&
                                data.buffer instanceof ArrayBuffer
                            ) {
                                var offset = data.byteOffset || 0;
                                var available = Math.max(data.buffer.byteLength - offset, 0);
                                var length = typeof data.byteLength === 'number'
                                    ? Math.min(data.byteLength, available)
                                    : available;
                                workbookData = new Uint8Array(data.buffer, offset, length);
                            }
                        }

                        // XLSX.read can handle both .xls and .xlsx files
                        var workbook = XLSX.read(workbookData, { 
                            type: 'array',
                            cellDates: true,
                            cellNF: false,
                            cellStyles: false
                        });
                        
                        if (!workbook || !workbook.SheetNames || workbook.SheetNames.length === 0) {
                            self.showError($container, 'This spreadsheet appears to be empty or invalid.', downloadUrl);
                            return;
                        }

                        // Double-check this is still the current request before updating DOM
                        if (self.currentFileId !== requestId) {
                            return;
                        }

                        var html = self.buildXlsxHtml(workbook);
                        $container
                            .removeClass('has-error')
                            .addClass('has-content')
                            .empty()
                            .html(html);
                    } catch (err) {
                        console.error('XLSX parse error:', err);
                        self.showError($container, 'Unable to parse this Excel file. The file may be corrupted or in an unsupported format.', downloadUrl);
                    }
                })
                .catch(function(err) {
                    if (self.currentFileId !== requestId) {
                        return;
                    }
                    console.error('XLSX fetch error:', err);
                    self.showError($container, 'Unable to load the spreadsheet. Please check your connection and try again.', downloadUrl);
                });
        },

        /**
         * Build HTML for XLSX workbook with tabs
         */
        buildXlsxHtml: function(workbook) {
            var sheetNames = workbook.SheetNames;
            if (!sheetNames || sheetNames.length === 0) {
                return '<p class="eap-xlsx-empty">This spreadsheet is empty.</p>';
            }

            var html = ['<div class="eap-xlsx-viewer">'];

            // Build tabs (only if multiple sheets)
            if (sheetNames.length > 1) {
                html.push('<div class="eap-xlsx-tabs" role="tablist">');
                for (var i = 0; i < sheetNames.length; i++) {
                    var isActive = i === 0 ? ' is-active' : '';
                    var ariaSelected = i === 0 ? 'true' : 'false';
                    var escapedSheetName = this.escapeAttr(sheetNames[i]);
                    html.push(
                        '<button class="eap-xlsx-tab' + isActive + '" ' +
                        'data-sheet="' + escapedSheetName + '" ' +
                        'role="tab" aria-selected="' + ariaSelected + '" ' +
                        'aria-controls="sheet-' + i + '">' +
                        this.escapeHtml(sheetNames[i]) +
                        '</button>'
                    );
                }
                html.push('</div>');
            }

            // Build sheet contents
            html.push('<div class="eap-xlsx-sheets">');
            for (var j = 0; j < sheetNames.length; j++) {
                var sheetName = sheetNames[j];
                var sheet = workbook.Sheets[sheetName];
                var isFirst = j === 0 ? ' is-visible' : '';
                var escapedSheetName = this.escapeAttr(sheetName);
                var tableHtml = this.sheetToHtml(sheet);
                
                html.push(
                    '<div class="eap-xlsx-sheet' + isFirst + '" ' +
                    'id="sheet-' + j + '" ' +
                    'data-sheet="' + escapedSheetName + '" ' +
                    'data-sheet-index="' + j + '" ' +
                    'role="tabpanel" ' +
                    'aria-labelledby="tab-' + j + '">' +
                    '<div class="eap-xlsx-scroll">' + tableHtml + '</div>' +
                    '</div>'
                );
            }
            html.push('</div>');
            html.push('</div>');

            return html.join('');
        },

        /**
         * Convert sheet to HTML table
         */
        sheetToHtml: function(sheet) {
            if (!sheet) {
                return '<p class="eap-xlsx-empty">This sheet is empty.</p>';
            }

            try {
                var range = this.getSheetDataBounds(sheet);

                if (!range) {
                    return '<p class="eap-xlsx-empty">This sheet is empty.</p>';
                }

                if (typeof range.s !== 'object' || typeof range.e !== 'object' ||
                    typeof range.s.r !== 'number' || typeof range.s.c !== 'number' ||
                    typeof range.e.r !== 'number' || typeof range.e.c !== 'number' ||
                    range.e.r < range.s.r || range.e.c < range.s.c) {
                    console.warn('Invalid range structure:', range);
                    return '<p class="eap-xlsx-error">Unable to determine sheet range.</p>';
                }

                var startRow = range.s.r;
                var endRow = Math.min(range.e.r, startRow + 499);
                var startCol = range.s.c;
                var endCol = Math.min(range.e.c, startCol + 99);

                var html = ['<table class="eap-xlsx-table">'];
                var hasVisibleContent = false;

                for (var r = startRow; r <= endRow; r++) {
                    html.push('<tr>');
                    for (var c = startCol; c <= endCol; c++) {
                        var addr = XLSX.utils.encode_cell({ r: r, c: c });
                        var cell = sheet[addr];
                        var val = this.formatCellValue(cell);
                        if (!hasVisibleContent && val !== '') {
                            hasVisibleContent = true;
                        }
                        var tag = r === startRow ? 'th' : 'td';
                        html.push('<' + tag + '>' + this.escapeHtml(val) + '</' + tag + '>');
                    }
                    html.push('</tr>');
                }

                if (!hasVisibleContent) {
                    return '<p class="eap-xlsx-empty">This sheet contains no previewable values. Download the file to view its full contents.</p>';
                }

                html.push('</table>');

                if (range.e.r > endRow || range.e.c > endCol) {
                    var notes = [];
                    if (range.e.r > endRow) {
                        notes.push('first ' + (endRow - startRow + 1) + ' rows');
                    }
                    if (range.e.c > endCol) {
                        notes.push('first ' + (endCol - startCol + 1) + ' columns');
                    }
                    html.push('<p class="eap-xlsx-note">Showing ' + notes.join(' and ') + '. Download to view all data.</p>');
                }

                return html.join('');
            } catch (err) {
                console.error('Sheet to HTML error:', err);
                return '<p class="eap-xlsx-error">Unable to render this sheet: ' + this.escapeHtml(err.message || 'Unknown error') + '</p>';
            }
        },

        /**
         * Get sheet range - first tries !ref property, then scans cells
         */
        getSheetDataBounds: function(sheet) {
            if (!sheet) {
                return null;
            }

            // First, try to use the !ref property (standard XLSX.js way)
            if (sheet['!ref']) {
                try {
                    var range = XLSX.utils.decode_range(sheet['!ref']);
                    if (range && typeof range.s === 'object' && typeof range.e === 'object') {
                        // Validate the range has actual content
                        var hasContent = false;
                        var checkLimit = Math.min(10, range.e.r - range.s.r + 1);
                        for (var checkRow = range.s.r; checkRow < range.s.r + checkLimit && !hasContent; checkRow++) {
                            for (var checkCol = range.s.c; checkCol <= range.e.c && !hasContent; checkCol++) {
                                var checkAddr = XLSX.utils.encode_cell({ r: checkRow, c: checkCol });
                                if (sheet[checkAddr] && this.cellHasDisplayValue(sheet[checkAddr])) {
                                    hasContent = true;
                                }
                            }
                        }
                        if (hasContent) {
                            return range;
                        }
                    }
                } catch (e) {
                    console.warn('Failed to decode !ref:', e);
                }
            }

            // Fallback: scan cell keys to determine bounds
            var minRow = Infinity;
            var maxRow = -1;
            var minCol = Infinity;
            var maxCol = -1;
            var foundCells = false;

            for (var key in sheet) {
                if (!sheet.hasOwnProperty(key) || key.charAt(0) === '!') {
                    continue;
                }

                if (/^[A-Za-z]+\d+$/.test(key)) {
                    var decoded;
                    try {
                        decoded = XLSX.utils.decode_cell(key.toUpperCase());
                    } catch (e) {
                        continue;
                    }

                    var cell = sheet[key];
                    if (!this.cellHasDisplayValue(cell)) {
                        continue;
                    }

                    foundCells = true;
                    if (decoded.r < minRow) minRow = decoded.r;
                    if (decoded.r > maxRow) maxRow = decoded.r;
                    if (decoded.c < minCol) minCol = decoded.c;
                    if (decoded.c > maxCol) maxCol = decoded.c;
                }
            }

            if (!foundCells || maxRow === -1 || maxCol === -1 || minRow === Infinity || minCol === Infinity) {
                return null;
            }

            return {
                s: { r: minRow, c: minCol },
                e: { r: maxRow, c: maxCol }
            };
        },

        cellHasDisplayValue: function(cell) {
            if (!cell) {
                return false;
            }

            if (cell.w !== undefined && cell.w !== null) {
                if (typeof cell.w === 'string') {
                    if (cell.w.trim() !== '') {
                        return true;
                    }
                } else {
                    return true;
                }
            }

            if (cell.v !== undefined && cell.v !== null) {
                if (typeof cell.v === 'string') {
                    if (cell.v.trim() !== '') {
                        return true;
                    }
                } else {
                    return true;
                }
            }

            if (cell.f && typeof cell.f === 'string' && cell.f.trim() !== '') {
                return true;
            }

            return false;
        },

        formatCellValue: function(cell) {
            if (!cell) {
                return '';
            }

            if (cell.w !== undefined && cell.w !== null) {
                var formatted = String(cell.w);
                return formatted;
            }

            if (cell.v !== undefined && cell.v !== null) {
                return String(cell.v);
            }

            if (cell.f && typeof cell.f === 'string' && cell.f.trim() !== '') {
                return '=' + cell.f;
            }

            return '';
        },

        /**
         * Switch active sheet tab
         */
        switchSheet: function(sheetName, $tab) {
            if (!$tab || !$tab.length) {
                return;
            }
            
            var $viewer = $tab.closest('.eap-xlsx-viewer');
            if (!$viewer.length) {
                console.warn('switchSheet: Viewer not found');
                return;
            }
            
            // Update tabs
            $viewer.find('.eap-xlsx-tab')
                .removeClass('is-active')
                .attr('aria-selected', 'false');
            $tab.addClass('is-active').attr('aria-selected', 'true');
            
            // Update sheets - try multiple matching strategies
            $viewer.find('.eap-xlsx-sheet').removeClass('is-visible');
            
            // Strategy 1: Match by data-sheet attribute (exact match)
            var $targetSheet = $viewer.find('.eap-xlsx-sheet[data-sheet="' + this.escapeAttr(sheetName) + '"]');
            
            // Strategy 2: If not found, try unescaped match
            if (!$targetSheet.length) {
                $viewer.find('.eap-xlsx-sheet').each(function() {
                    var $sheet = $(this);
                    var attrValue = $sheet.attr('data-sheet');
                    if (attrValue === sheetName || attrValue === $tab.attr('data-sheet')) {
                        $targetSheet = $sheet;
                        return false; // break
                    }
                });
            }
            
            // Strategy 3: Match by index if available
            if (!$targetSheet.length) {
                var tabIndex = $tab.index();
                $targetSheet = $viewer.find('.eap-xlsx-sheet[data-sheet-index="' + tabIndex + '"]');
            }
            
            if ($targetSheet.length) {
                $targetSheet.addClass('is-visible');
                // Scroll to top of the sheet
                var $scroll = $targetSheet.find('.eap-xlsx-scroll');
                if ($scroll.length) {
                    $scroll.scrollTop(0);
                }
            } else {
                console.warn('switchSheet: Target sheet not found', { sheetName: sheetName, tabData: $tab.data() });
                // Fallback: show first sheet
                var $firstSheet = $viewer.find('.eap-xlsx-sheet').first();
                if ($firstSheet.length) {
                    $firstSheet.addClass('is-visible');
                }
            }
        },

        /**
         * Render DOCX file as document viewer
         */
        renderDocx: function(url, $container, downloadUrl) {
            var self = this;

            if (!this.checkLibraries('docx')) {
                this.showError($container, 'Word document viewer library not loaded. Please download the file to view.', downloadUrl);
                return;
            }

            this.cancelActiveRequest();
            this.requestCounter++;
            var requestId = this.requestCounter;
            this.currentFileUrl = url;
            this.currentFileId = requestId;

            $container
                .removeClass('has-error has-content')
                .empty()
                .html(this.getLoadingHtml('Loading document...'));

            // Add cache-busting parameter to URL
            var cacheBustedUrl = this.addCacheBuster(url);

            this.fetchFile(cacheBustedUrl, 'arraybuffer')
                .then(function(data) {
                    if (self.currentFileId !== requestId) {
                        return;
                    }

                    return mammoth.convertToHtml({ arrayBuffer: data }, {
                        styleMap: [
                            "p[style-name='Heading 1'] => h1.eap-docx-h1",
                            "p[style-name='Heading 2'] => h2.eap-docx-h2",
                            "p[style-name='Heading 3'] => h3.eap-docx-h3",
                            "p[style-name='Title'] => h1.eap-docx-title",
                            "b => strong",
                            "i => em",
                            "u => u"
                        ]
                    });
                })
                .then(function(result) {
                    if (!result || self.currentFileId !== requestId) {
                        return;
                    }

                    var docHtml = result.value || '';
                    if (!docHtml.trim()) {
                        self.showError($container, 'This document appears to be empty.', downloadUrl);
                        return;
                    }

                    var html = [
                        '<div class="eap-docx-viewer">',
                        '<div class="eap-docx-page">',
                        '<div class="eap-docx-content">',
                        docHtml,
                        '</div>',
                        '</div>',
                        '</div>'
                    ].join('');

                    $container
                        .addClass('has-content')
                        .empty()
                        .html(html);

                    // Report any conversion messages
                    if (result.messages && result.messages.length > 0) {
                        console.log('Mammoth conversion messages:', result.messages);
                    }
                })
                .catch(function(err) {
                    if (self.currentFileId !== requestId) {
                        return;
                    }
                    console.error('DOCX parse error:', err);
                    self.showError($container, 'Unable to parse this Word document.', downloadUrl);
                });
        },

        /**
         * Render PPTX file as carousel
         */
        renderPptx: function(url, $container, downloadUrl) {
            var self = this;

            if (!this.checkLibraries('pptx')) {
                this.showError($container, 'PowerPoint viewer library not loaded. Please download the file to view.', downloadUrl);
                return;
            }

            if (!$container || !$container.length) {
                console.error('PPTX render: Invalid container');
                return;
            }

            this.cancelActiveRequest();
            this.requestCounter++;
            var requestId = this.requestCounter;
            this.currentFileUrl = url;
            this.currentFileId = requestId;
            this.currentSlideIndex = 0;

            $container
                .removeClass('has-error has-content')
                .empty()
                .html(this.getLoadingHtml('Loading presentation...'));

            // Add cache-busting parameter to URL
            var cacheBustedUrl = this.addCacheBuster(url);

            this.fetchFile(cacheBustedUrl, 'arraybuffer')
                .then(function(data) {
                    if (self.currentFileId !== requestId) {
                        return;
                    }

                    if (!data || data.byteLength === 0) {
                        self.showError($container, 'The file appears to be empty.', downloadUrl);
                        return;
                    }

                    // Check if this is a binary .ppt file (not .pptx)
                    // PPTX files start with PK (ZIP signature), .ppt files don't
                    var uint8Array = new Uint8Array(data);
                    var isZipFile = uint8Array[0] === 0x50 && uint8Array[1] === 0x4B; // "PK" signature
                    
                    if (!isZipFile) {
                        // This is likely a binary .ppt file, which we can't parse
                        self.showError($container, 'Binary PowerPoint (.ppt) files cannot be previewed. Please download the file or convert it to .pptx format.', downloadUrl);
                        return;
                    }

                    var zip = new JSZip();
                    return zip.loadAsync(data);
                })
                .then(function(zip) {
                    if (!zip || self.currentFileId !== requestId) {
                        return;
                    }

                    return self.parsePptxSlides(zip);
                })
                .then(function(slides) {
                    if (!slides || self.currentFileId !== requestId) {
                        return;
                    }

                    if (slides.length === 0) {
                        self.showError($container, 'No slides found in this presentation. The file may be corrupted or in an unsupported format.', downloadUrl);
                        return;
                    }

                    self.totalSlides = slides.length;
                    var html = self.buildPptxHtml(slides);
                    $container
                        .removeClass('has-error')
                        .addClass('has-content')
                        .empty()
                        .html(html);
                    self.updateSlideIndicator();
                })
                .catch(function(err) {
                    if (self.currentFileId !== requestId) {
                        return;
                    }
                    console.error('PPTX parse error:', err);
                    // Check if it's a JSZip error (likely binary .ppt file)
                    if (err.message && (err.message.indexOf('corrupted') !== -1 || err.message.indexOf('Invalid') !== -1)) {
                        self.showError($container, 'This appears to be a binary PowerPoint (.ppt) file, which cannot be previewed. Please download the file or convert it to .pptx format.', downloadUrl);
                    } else {
                        self.showError($container, 'Unable to parse this PowerPoint file. The file may be corrupted or in an unsupported format.', downloadUrl);
                    }
                });
        },

        /**
         * Parse PPTX slides from ZIP
         */
        parsePptxSlides: function(zip) {
            var self = this;
            var slides = [];
            var slideFiles = [];

            // Find slide XML files - use case-insensitive matching and normalize paths
            zip.forEach(function(relativePath, file) {
                if (!file || file.dir) {
                    return; // Skip directories
                }
                
                // Normalize path: lowercase and use forward slashes
                var normalizedPath = relativePath.toLowerCase().replace(/\\/g, '/');
                
                // Match slide files: ppt/slides/slide1.xml, ppt/slides/slide2.xml, etc.
                // Also handle variations like ppt/slides/slide1.xml or slides/slide1.xml
                if (normalizedPath.match(/^(ppt\/)?slides\/slide\d+\.xml$/i)) {
                    slideFiles.push({
                        path: relativePath, // Keep original path for file access
                        normalizedPath: normalizedPath
                    });
                }
            });

            // If no slides found with standard path, try alternative detection
            if (slideFiles.length === 0) {
                zip.forEach(function(relativePath, file) {
                    if (!file || file.dir) {
                        return; // Skip directories
                    }
                    
                    var normalizedPath = relativePath.toLowerCase().replace(/\\/g, '/');
                    // Broader match: any file that looks like a slide
                    // Exclude relationship files and other metadata
                    if (normalizedPath.indexOf('/slides/slide') !== -1 || 
                        normalizedPath.indexOf('slides/slide') !== -1) {
                        if (normalizedPath.endsWith('.xml') &&
                            !normalizedPath.includes('_rels') &&
                            !normalizedPath.includes('theme') &&
                            !normalizedPath.includes('notesSlide')) {
                            slideFiles.push({
                                path: relativePath,
                                normalizedPath: normalizedPath
                            });
                        }
                    }
                });
            }

            // If still no slides, try even broader search
            if (slideFiles.length === 0) {
                zip.forEach(function(relativePath, file) {
                    if (!file || file.dir) {
                        return;
                    }
                    
                    var normalizedPath = relativePath.toLowerCase().replace(/\\/g, '/');
                    // Look for any XML file with "slide" in the name
                    if (normalizedPath.match(/slide\d+\.xml$/i) && 
                        !normalizedPath.includes('_rels') &&
                        !normalizedPath.includes('notesSlide')) {
                        slideFiles.push({
                            path: relativePath,
                            normalizedPath: normalizedPath
                        });
                    }
                });
            }

            // Sort slides by number
            slideFiles.sort(function(a, b) {
                var matchA = a.normalizedPath.match(/slide(\d+)\.xml$/i);
                var matchB = b.normalizedPath.match(/slide(\d+)\.xml$/i);
                var numA = matchA ? parseInt(matchA[1], 10) : 0;
                var numB = matchB ? parseInt(matchB[1], 10) : 0;
                return numA - numB;
            });

            // Remove duplicates based on slide number
            var seenNumbers = {};
            slideFiles = slideFiles.filter(function(slideInfo) {
                var match = slideInfo.normalizedPath.match(/slide(\d+)\.xml$/i);
                var num = match ? parseInt(match[1], 10) : 0;
                if (seenNumbers[num]) {
                    return false;
                }
                seenNumbers[num] = true;
                return true;
            });

            // Parse each slide
            var promises = slideFiles.map(function(slideInfo, index) {
                var file = zip.file(slideInfo.path);
                if (!file) {
                    console.warn('Could not access slide file:', slideInfo.path);
                    return Promise.resolve(null);
                }
                return file.async('string').then(function(xml) {
                    if (!xml || xml.trim().length === 0) {
                        console.warn('Empty slide XML:', slideInfo.path);
                        return null;
                    }
                    return self.parseSlideXml(xml, index + 1, zip);
                }).catch(function(err) {
                    console.warn('Error parsing slide:', slideInfo.path, err);
                    return null;
                });
            });

            return Promise.all(promises).then(function(parsedSlides) {
                var validSlides = parsedSlides.filter(function(s) { 
                    return s !== null && s !== undefined; 
                });
                
                if (validSlides.length === 0 && slideFiles.length > 0) {
                    console.warn('Found slide files but could not parse any slides');
                }
                
                return validSlides;
            });
        },

        /**
         * Parse a single slide XML
         */
        parseSlideXml: function(xml, slideNum, zip) {
            var self = this;
            var parser = new DOMParser();
            var doc = parser.parseFromString(xml, 'application/xml');
            
            var slide = {
                number: slideNum,
                title: '',
                content: [],
                images: []
            };

            // Check for parsing errors
            var parseError = doc.querySelector('parsererror');
            if (parseError) {
                console.warn('XML parse error for slide', slideNum);
                return slide;
            }

            // Extract text content - try multiple selector strategies
            var texts = [];
            
            // Strategy 1: Direct tag name with namespace prefix (escaped colon)
            var textNodes = doc.querySelectorAll('a\\:t');
            
            // Strategy 2: If Strategy 1 found nothing, try without namespace
            if (!textNodes || textNodes.length === 0) {
                textNodes = doc.getElementsByTagName('t');
            }
            
            // Strategy 3: Try with getElementsByTagNameNS for proper namespace handling
            if (!textNodes || textNodes.length === 0) {
                textNodes = doc.getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 't');
            }

            // Strategy 4: Use XPath as fallback for complex namespace scenarios
            if (!textNodes || textNodes.length === 0) {
                try {
                    // Get all elements and filter by local name
                    var allElements = doc.getElementsByTagName('*');
                    var tempNodes = [];
                    for (var i = 0; i < allElements.length; i++) {
                        if (allElements[i].localName === 't') {
                            tempNodes.push(allElements[i]);
                        }
                    }
                    textNodes = tempNodes;
                } catch (e) {
                    console.warn('Fallback text extraction failed:', e);
                }
            }

            // Process found text nodes
            if (textNodes && textNodes.length > 0) {
                // Convert to array if needed (for NodeList)
                var nodeArray = Array.prototype.slice.call(textNodes);
                nodeArray.forEach(function(node) {
                    var text = (node.textContent || '').trim();
                    if (text && text.length > 0) {
                        texts.push(text);
                    }
                });
            }

            // Deduplicate and clean up texts
            var uniqueTexts = [];
            var seenTexts = {};
            texts.forEach(function(text) {
                // Skip very short text fragments that are likely formatting artifacts
                if (text.length < 2 && !/\w/.test(text)) {
                    return;
                }
                var key = text.toLowerCase();
                if (!seenTexts[key]) {
                    seenTexts[key] = true;
                    uniqueTexts.push(text);
                }
            });

            // First text is usually the title
            if (uniqueTexts.length > 0) {
                slide.title = uniqueTexts[0];
                slide.content = uniqueTexts.slice(1);
            }

            return slide;
        },

        /**
         * Build PPTX carousel HTML
         */
        buildPptxHtml: function(slides) {
            var html = ['<div class="eap-pptx-viewer">'];
            
            // Main slide display
            html.push('<div class="eap-pptx-main">');
            html.push('<button class="eap-pptx-nav eap-pptx-nav-prev" aria-label="Previous slide">&#10094;</button>');
            
            html.push('<div class="eap-pptx-slides">');
            for (var i = 0; i < slides.length; i++) {
                var slide = slides[i];
                var isActive = i === 0 ? ' is-active' : '';
                
                html.push('<div class="eap-pptx-slide' + isActive + '" data-index="' + i + '">');
                html.push('<div class="eap-pptx-slide-number">Slide ' + slide.number + ' of ' + slides.length + '</div>');
                
                if (slide.title) {
                    html.push('<h2 class="eap-pptx-slide-title">' + this.escapeHtml(slide.title) + '</h2>');
                }
                
                if (slide.content.length > 0) {
                    html.push('<div class="eap-pptx-slide-content">');
                    html.push('<ul>');
                    for (var j = 0; j < slide.content.length; j++) {
                        html.push('<li>' + this.escapeHtml(slide.content[j]) + '</li>');
                    }
                    html.push('</ul>');
                    html.push('</div>');
                }
                
                if (!slide.title && slide.content.length === 0) {
                    html.push('<p class="eap-pptx-slide-empty">This slide contains visual content only.</p>');
                }
                
                html.push('</div>');
            }
            html.push('</div>');
            
            html.push('<button class="eap-pptx-nav eap-pptx-nav-next" aria-label="Next slide">&#10095;</button>');
            html.push('</div>');
            
            // Thumbnail strip
            if (slides.length > 1) {
                html.push('<div class="eap-pptx-thumbs">');
                for (var k = 0; k < slides.length; k++) {
                    var thumbActive = k === 0 ? ' is-active' : '';
                    var slideTitle = slides[k].title || 'Slide ' + (k + 1);
                    html.push(
                        '<button class="eap-pptx-thumb' + thumbActive + '" data-index="' + k + '" ' +
                        'aria-label="Go to slide ' + (k + 1) + '">' +
                        '<span class="eap-pptx-thumb-num">' + (k + 1) + '</span>' +
                        '<span class="eap-pptx-thumb-title">' + this.escapeHtml(this.truncate(slideTitle, 20)) + '</span>' +
                        '</button>'
                    );
                }
                html.push('</div>');
            }
            
            // Slide counter
            html.push('<div class="eap-pptx-counter">');
            html.push('<span class="eap-pptx-current">1</span> / <span class="eap-pptx-total">' + slides.length + '</span>');
            html.push('</div>');
            
            html.push('</div>');
            
            return html.join('');
        },

        /**
         * Navigate to next/prev slide
         */
        navigateSlide: function(direction) {
            var newIndex = this.currentSlideIndex + direction;
            if (newIndex >= 0 && newIndex < this.totalSlides) {
                this.goToSlide(newIndex);
            }
        },

        /**
         * Go to specific slide
         */
        goToSlide: function(index) {
            if (index < 0 || index >= this.totalSlides) {
                return;
            }

            this.currentSlideIndex = index;
            
            var $viewer = $('.eap-pptx-viewer');
            
            // Update slides
            $viewer.find('.eap-pptx-slide').removeClass('is-active');
            $viewer.find('.eap-pptx-slide[data-index="' + index + '"]').addClass('is-active');
            
            // Update thumbnails
            $viewer.find('.eap-pptx-thumb').removeClass('is-active');
            $viewer.find('.eap-pptx-thumb[data-index="' + index + '"]').addClass('is-active');
            
            // Scroll thumbnail into view
            var $activeThumb = $viewer.find('.eap-pptx-thumb.is-active');
            if ($activeThumb.length) {
                $activeThumb[0].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }
            
            this.updateSlideIndicator();
        },

        /**
         * Update slide counter display
         */
        updateSlideIndicator: function() {
            $('.eap-pptx-current').text(this.currentSlideIndex + 1);
        },

        /**
         * Add cache-busting parameter to URL
         */
        addCacheBuster: function(url) {
            if (!url) {
                return url;
            }
            var separator = url.indexOf('?') === -1 ? '?' : '&';
            return url + separator + '_cb=' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        },

        /**
         * Fetch file as ArrayBuffer or text
         */
        fetchFile: function(url, responseType) {
            var self = this;
            
            return new Promise(function(resolve, reject) {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', url, true);
                xhr.responseType = responseType || 'arraybuffer';
                xhr.withCredentials = true;
                
                // Set headers to prevent caching
                xhr.setRequestHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
                xhr.setRequestHeader('Pragma', 'no-cache');
                
                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve(xhr.response);
                    } else {
                        reject(new Error('HTTP ' + xhr.status));
                    }
                };
                
                xhr.onerror = function() {
                    reject(new Error('Network error'));
                };
                
                xhr.onabort = function() {
                    reject(new Error('Request aborted'));
                };
                
                self.activeRequest = xhr;
                xhr.send();
            });
        },

        /**
         * Cancel any active request
         */
        cancelActiveRequest: function() {
            if (this.activeRequest && this.activeRequest.abort) {
                this.activeRequest.abort();
            }
            this.activeRequest = null;
            this.currentFileUrl = null;
            // Note: We don't clear currentFileId here because we want to use it 
            // to check if a response belongs to a superseded request
        },

        /**
         * Show error message
         */
        showError: function($container, message, downloadUrl) {
            var fallbackLink = '';
            if (downloadUrl) {
                fallbackLink = ' <a href="' + this.escapeAttr(downloadUrl) + '" target="_blank" rel="noopener noreferrer">Download the file</a>.';
            }
            
            $container
                .addClass('has-error')
                .html(
                    '<div class="eap-office-error">' +
                    '<span class="eap-office-error-icon">⚠️</span>' +
                    '<p>' + this.escapeHtml(message) + fallbackLink + '</p>' +
                    '</div>'
                );
        },

        /**
         * Get loading spinner HTML
         */
        getLoadingHtml: function(message) {
            return '<div class="eap-office-loading">' +
                '<span class="eap-secure-viewer__spinner" aria-hidden="true"></span>' +
                this.escapeHtml(message || 'Loading...') +
                '</div>';
        },

        /**
         * Escape HTML entities
         */
        escapeHtml: function(str) {
            return $('<div>').text(str || '').html();
        },

        /**
         * Escape for attribute
         */
        escapeAttr: function(str) {
            return $('<div>').text(str || '').html()
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        /**
         * Truncate string
         */
        truncate: function(str, maxLen) {
            if (!str || str.length <= maxLen) {
                return str || '';
            }
            return str.substring(0, maxLen - 3) + '...';
        },

        /**
         * Reset viewer state
         */
        reset: function() {
            this.cancelActiveRequest();
            this.currentFileUrl = null;
            this.currentFileId = null;
            this.currentSlideIndex = 0;
            this.totalSlides = 0;
        }
    };

    // Export to window
    window.EapOfficeViewer = OfficeViewer;

    // Initialize on document ready
    $(document).ready(function() {
        OfficeViewer.init();
    });

})(jQuery);