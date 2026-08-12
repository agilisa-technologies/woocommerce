(function () {
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = window.wc.wcSettings.getSetting;
    var createElement = window.wp.element.createElement;

    var settings = getSetting('agilpay_data', {});
    var title = settings.title || 'Agilpay';
    var description = settings.description || 'Paga con Agilpay';
    var icon = settings.icon || '';

    var label = createElement(
        'span',
        { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
        icon ? createElement('img', { src: icon, alt: title, style: { height: '20px' } }) : null,
        title
    );

    registerPaymentMethod({
        name: 'agilpay',
        label: label,
        content: createElement('p', null, description),
        edit: createElement('p', null, description),
        canMakePayment: function () { return true; },
        ariaLabel: title,
        supports: {
            features: settings.supports || ['products'],
        },
    });
})();
