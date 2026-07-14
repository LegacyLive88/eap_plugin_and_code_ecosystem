(function (wp) {
  var el = wp.element.createElement
  var components = wp.components
  var blockControls = wp.blockEditor.BlockControls
  var inspectorControls = wp.blockEditor.InspectorControls
  var useBlockProps = wp.blockEditor.useBlockProps

  // Registering the Block for employee cabinet shortcode
  wp.blocks.registerBlockType('amelia/employee-cabinet-gutenberg-block', {
    apiVersion: 3,
    title: wpAmeliaLabels.employee_cabinet_gutenberg_block.title,
    description: wpAmeliaLabels.employee_cabinet_gutenberg_block.description,
    icon: window.ameliaBlockIcon,
    category: 'amelia-blocks',
    keywords: [
      'amelia',
      'employee panel'
    ],
    supports: {
      customClassName: false,
      html: false
    },
    attributes: {
      short_code: {
        type: 'string',
        default: '[ameliaemployeepanel]'
      },
      trigger: {
        type: 'string',
        default: ''
      },
      appointmentsPanel: {
        type: 'boolean',
        default: true
      },
      eventsPanel: {
        type: 'boolean',
        default: true
      },
      profilePanel: {
        type: 'boolean',
        default: false
      }
    },
    edit: function (props) {
      var inspectorElements = []
      var attributes = props.attributes

      function getShortCode (props, attributes) {
        var shortCode = '[ameliaemployeepanel'

        if (!attributes.appointmentsPanel && !attributes.eventsPanel && attributes.profilePanel) {
          shortCode = 'Notice: Please select at least one panel.'
        } else {
          if (attributes.trigger) {
            shortCode += ' trigger=' + attributes.trigger + ''
          }

          if (attributes.appointmentsPanel) {
            shortCode += ' appointments=1'
          }

          if (attributes.eventsPanel) {
            shortCode += ' events=1'
          }

          if (attributes.profilePanel) {
            shortCode += ' profile-hidden=1'
          }

          shortCode += ']'
        }

        props.setAttributes({short_code: shortCode})

        return shortCode
      }

      inspectorElements.push(el(components.PanelRow,
        {},
        el('label', {htmlFor: 'amelia-js-appointments-panel'}, wpAmeliaLabels.appointments),
        el(components.FormToggle, {
          id: 'amelia-js-appointments-panel',
          checked: attributes.appointmentsPanel,
          onChange: function () {
            return props.setAttributes({appointmentsPanel: !props.attributes.appointmentsPanel})
          }
        })
      ))

      inspectorElements.push(el(components.PanelRow,
        {},
        el('label', {htmlFor: 'amelia-js-events-panel'}, wpAmeliaLabels.events),
        el(components.FormToggle, {
          id: 'amelia-js-events-panel',
          checked: attributes.eventsPanel,
          onChange: function () {
            return props.setAttributes({eventsPanel: !props.attributes.eventsPanel})
          }
        })
      ))

      inspectorElements.push(el(components.PanelRow,
        {},
        el('label', {htmlFor: 'amelia-js-profile-panel'}, wpAmeliaLabels.profile),
        el(components.FormToggle, {
          id: 'amelia-js-profile-panel',
          checked: attributes.profilePanel,
          onChange: function () {
            return props.setAttributes({profilePanel: !props.attributes.profilePanel})
          }
        })
      ))

      inspectorElements.push(el('div', {style: {marginBottom: '1em'}}, ''))

      inspectorElements.push(el(components.TextControl, {
        id: 'amelia-js-trigger',
        label: wpAmeliaLabels.manually_loading,
        value: attributes.trigger,
        help: wpAmeliaLabels.manually_loading_description,
        onChange: function (TextControl) {
          return props.setAttributes({trigger: TextControl})
        }
      }))

      var blockProps = useBlockProps()
      return el('div', blockProps,
        el(blockControls, {key: 'controls'}),
        el(inspectorControls, {key: 'inspector'},
          el(components.PanelBody, {initialOpen: true},
            inspectorElements
          )
        ),
        el('div', {className: 'amelia-gutenberg-placeholder'},
          el('div', {className: 'amelia-gutenberg-placeholder__header'},
            el('div', {className: 'amelia-gutenberg-placeholder__icon'}, window.ameliaBlockIcon || ''),
            el('div', {className: 'amelia-gutenberg-placeholder__title'}, 'Amelia - Employee Panel')
          ),
          el('div', {className: 'amelia-gutenberg-placeholder__shortcode'},
            getShortCode(props, props.attributes)
          )
        )
      )
    },

    save: function () {
      return null
    },
    deprecated: [
      {
        attributes: {
          short_code: { type: 'string', default: '[ameliaemployeepanel]' },
          trigger: { type: 'string', default: '' },
          appointmentsPanel: { type: 'boolean', default: true },
          eventsPanel: { type: 'boolean', default: true },
          profilePanel: { type: 'boolean', default: false }
        },
        save: function (props) {
          return el('div', {}, props.attributes.short_code)
        }
      }
    ]
  })
})(
  window.wp
)
