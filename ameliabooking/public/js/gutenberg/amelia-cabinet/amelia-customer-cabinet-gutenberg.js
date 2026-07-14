(function (wp) {

  var el = wp.element.createElement
  var components = wp.components
  var blockControls = wp.blockEditor.BlockControls
  var inspectorControls = wp.blockEditor.InspectorControls
  var useBlockProps = wp.blockEditor.useBlockProps

  // Registering the Block for customer cabinet shortcode
  wp.blocks.registerBlockType('amelia/customer-cabinet-gutenberg-block', {
    apiVersion: 3,
    title: wpAmeliaLabels.customer_cabinet_gutenberg_block.title,
    description: wpAmeliaLabels.customer_cabinet_gutenberg_block.description,
    icon: window.ameliaBlockIcon,
    category: 'amelia-blocks',
    keywords: [
      'amelia',
      'customer panel'
    ],
    supports: {
      customClassName: false,
      html: false
    },
    attributes: {
      short_code: {
        type: 'string',
        default: '[ameliacustomerpanel]'
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
      }
    },
    edit: function (props) {
      var inspectorElements = []
      var attributes = props.attributes

      function getShortCode (props, attributes) {
        var shortCode = '[ameliacustomerpanel'

        if (!attributes.appointmentsPanel && !attributes.eventsPanel) {
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
            el('div', {className: 'amelia-gutenberg-placeholder__title'}, 'Amelia - Customer Panel')
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
          short_code: { type: 'string', default: '[ameliacustomerpanel]' },
          trigger: { type: 'string', default: '' },
          appointmentsPanel: { type: 'boolean', default: true },
          eventsPanel: { type: 'boolean', default: true }
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
