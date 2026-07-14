(function () {
    (function () {
        function renderLoginSecurityCodes() {
            var targets = document.querySelectorAll('.eap-login-security__qr');
            if (!targets.length) {
                return;
            }
    
            // Debugging: Check if library is loaded
            if (typeof QRCode === 'undefined') {
                console.error('EAP Security: QRCode library is missing or not loaded yet.');
                targets.forEach(function(target) {
                    target.innerHTML = '<p style="color:red; font-size:12px;">Error: QRCode library not found.</p>';
                });
                return;
            }
    
            var isFiniteNumber = Number.isFinite || function (value) {
                return typeof value === 'number' && isFinite(value);
            };
    
            targets.forEach(function (target) {
                var payload = target.getAttribute('data-eap-otpauth');
                if (!payload) {
                    console.warn('EAP Security: No OTPAuth payload found for QR container.');
                    return;
                }
    
                // Clear previous contents safely
                target.innerHTML = '';
    
                var requestedSize = parseInt(target.getAttribute('data-eap-qr-size'), 10);
                var size = isFiniteNumber(requestedSize) && requestedSize > 0 ? requestedSize : 220;
    
                try {
                    // Use integer 0 (Medium) directly as fallback since CorrectLevel isn't exposed in minified lib
                    var level = (QRCode.CorrectLevel && QRCode.CorrectLevel.M) ? QRCode.CorrectLevel.M : 0;
    
                    new QRCode(target, {
                        text: payload,
                        width: size,
                        height: size,
                        colorDark : "#000000",
                        colorLight : "#ffffff",
                        correctLevel: level
                    });
                } catch (error) {
                    console.error('EAP Security QR Generation Error:', error);
                    var fallback = document.createElement('p');
                    fallback.className = 'description';
                    fallback.textContent = (window.eapLoginSecurity && window.eapLoginSecurity.qrError) ?
                        window.eapLoginSecurity.qrError :
                        'Unable to render the QR code. Please enter the secret manually.';
                    target.appendChild(fallback);
                }
            });
        }
    
        // Render on load
        document.addEventListener('DOMContentLoaded', renderLoginSecurityCodes);
        
        // Also attempt to render immediately if DOM is already ready (e.g. late script injection)
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            renderLoginSecurityCodes();
        }
    })();

    document.addEventListener('DOMContentLoaded', renderLoginSecurityCodes);
    document.addEventListener('eap-login-security-render', renderLoginSecurityCodes);
})();
