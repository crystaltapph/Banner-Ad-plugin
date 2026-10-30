/* global wp, bapBlock */
(() => {
  const el = wp.element.createElement;
  wp.blocks.registerBlockType('banner-studio/banner', {
    apiVersion: 3,
    title: 'Banner Studio',
    description: 'Place one of your uploaded banners here.',
    icon: 'format-image',
    category: 'widgets',
    attributes: { bannerId: { type: 'integer', default: 0 } },
    supports: { html: false },
    edit: props => el('div', wp.blockEditor.useBlockProps(),
      el(wp.components.Placeholder, { label: 'Banner Studio', icon: 'format-image', instructions: 'Choose a banner. Its schedule, targeting, device settings, and page limit still apply.' },
        el(wp.components.SelectControl, {
          label: 'Banner',
          value: props.attributes.bannerId,
          options: [{ label: 'Select a banner…', value: 0 }, ...((window.bapBlock || {}).banners || [])],
          onChange: value => props.setAttributes({ bannerId: Number(value) })
        })
      )
    ),
    save: () => null
  });
})();
