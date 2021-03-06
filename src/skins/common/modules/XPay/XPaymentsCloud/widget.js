/*
 * X-Payments Cloud SDK - Payment Widget
 */

function XPaymentsWidget()
{
    this.serverDomain = 'xpayments.com';
    this.messageNamespace = 'xpayments.widget.';
    this.widgetId = this.generateId();
    this.previousHeight = -1;

    this.config = {
        debug: false,
        account: '',
        widgetKey: '',
        container: '',
        form: '',
        language: '',
        customerId: '',
        allowSaveCard: true,
        order: {
            total: -1,
            currency: ''
        }
    }

    this.handlers = {};

    this.bindedListener = false;
    this.bindedSubmit = false;

}

XPaymentsWidget.prototype.on = function(event, handler)
{
    if ('formSubmit' !== event) {

        this.handlers[event] = handler.bind(this);

    } else {
        var formElm = this.getFormElm();

        if (formElm) {
            if (this.bindedSubmit) {
                formElm.removeEventListener('submit', this.bindedSubmit);
            }
            this.bindedSubmit = handler.bind(this);
            formElm.addEventListener('submit', this.bindedSubmit);
        }
    }

    return this;
}


XPaymentsWidget.prototype.trigger = function(event, params)
{
    if ('function' === typeof this.handlers[event]) {
        this.handlers[event](params);
    }

    return this;
}

XPaymentsWidget.prototype.init = function(settings)
{
  for (var key in settings) {
      if ('undefined' !== typeof this.config[key]) {
          this.config[key] = settings[key];
      }
  }

  // Set default handlers
  // other events: fail, loaded, unloaded, submitReady

  this.on('formSubmit', function (domEvent) {
      // "this" here is the widget
      this.submit();
      domEvent.preventDefault();
  }).on('success', function(params) {
      var formElm = this.getFormElm();
      if (formElm) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'xpaymentsToken';
          input.value = params.token;
          formElm.appendChild(input);
          formElm.submit();
      }
  }).on('alert', function(params) {
      window.alert(params.message);
  });

  this.bindedListener = this.messageListener.bind(this);
  window.addEventListener('message', this.bindedListener);

  if (
      'undefined' !== typeof settings.autoload
      && settings.autoload
  ) {
      this.load();
  }

  return this;
}

XPaymentsWidget.prototype.generateId = function()
{
    return Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
}

XPaymentsWidget.prototype.getIframeId = function()
{
    return 'xpayments-' + this.widgetId;
}

XPaymentsWidget.prototype.getIframeElm = function()
{
    return document.getElementById(this.getIframeId());
}

XPaymentsWidget.prototype.getContainerElm = function()
{
    return this.safeQuerySelector(this.config.container);
}

XPaymentsWidget.prototype.getFormElm = function()
{
    return this.safeQuerySelector(this.config.form);
}

XPaymentsWidget.prototype.isValid = function()
{
    return this.getIframeElm() && this.getFormElm();
}

XPaymentsWidget.prototype.safeQuerySelector = function(selector)
{
    var elm = false;
    if (selector) {
        elm = document.querySelector(selector);
    }
    return elm;
}

XPaymentsWidget.prototype.load = function()
{
    var containerElm = this.getContainerElm();
    if (!containerElm) {
        return this;
    }

    var elm = this.getIframeElm();
    if (!elm) {
        elm = document.createElement('iframe');
        elm.id = this.getIframeId();
        elm.style.width = '100%';
        elm.style.height = '0';
        elm.style.overflow = 'hidden';
        elm.style.border = 'none';
        elm.setAttribute('scrolling', 'no');
        containerElm.appendChild(elm);
    }

    var url =
        this.getServerUrl() + '/payment.php' +
        '?widget_key=' + encodeURIComponent(this.config.widgetKey) +
        '&widget_id=' + encodeURIComponent(this.widgetId);
    if (this.config.customerId) {
        url += '&customer_id=' + encodeURIComponent(this.config.customerId);
    }
    if (this.config.language) {
        url += '&language=' + encodeURIComponent(this.config.language);
    }
    elm.src = url;

    return this;
}

XPaymentsWidget.prototype.getServerHost = function()
{
    return this.config.account + '.' + this.serverDomain;
}

XPaymentsWidget.prototype.getServerUrl = function()
{
    return 'https://' + this.getServerHost();
}

XPaymentsWidget.prototype.submit = function()
{
    this.postMessage({ event: 'xpayments.checkout.submit'});
}

XPaymentsWidget.prototype.showSaveCard = function(value)
{
    if ('undefined' === typeof value) {
        value = this.config.allowSaveCard;
    } else {
        this.config.allowSaveCard = (true === value);
    }
    this.postMessage({ event: 'xpayments.checkout.savecard', params: { show: value}});
}

XPaymentsWidget.prototype.resize = function(height)
{
    var elm = this.getIframeElm();
    if (elm) {
        this.previousHeight = elm.style.height;
        elm.style.height = height + 'px';
    }
}

XPaymentsWidget.prototype.setOrder = function(total, currency)
{
    if ('undefined' !== typeof total) {
        this.config.order.total = total;
        this.config.order.currency = currency;
    }
    this.postMessage({ event: 'xpayments.checkout.details', params: { total: this.config.order.total, currency: this.config.order.currency}});
}

XPaymentsWidget.prototype.destroy = function()
{
    if (this.bindedListener) {
        window.removeEventListener('message', this.bindedListener);
    }

    var formElm = this.getFormElm();
    if (this.bindedSubmit && formElm) {
        formElm.removeEventListener('submit', this.bindedSubmit);
    }

    var containerElm = this.getContainerElm();
    if (containerElm) {
        var elm = this.getIframeElm();
        if (elm && containerElm.contains(elm)) {
            containerElm.removeChild(elm);
        }
    }
}

XPaymentsWidget.prototype.messageListener = function(event)
{
    if (window.JSON) {
        var msg = false;
        if (-1 !== this.getServerUrl().toLowerCase().indexOf(event.origin.toLowerCase())) {
            try {
                msg = window.JSON.parse(event.data);
            } catch (e) {
                // Skip invalid messages
            }
        }

        if (
            msg &&
            msg.event &&
            0 === msg.event.indexOf(this.messageNamespace)
        ) {
            this.log('X-Payments Event: ' + msg.event + "\n" + window.JSON.stringify(msg.params));

            var eventType = msg.event.substr(this.messageNamespace.length);

            if ('loaded' === eventType) {
                this.showSaveCard();
                this.setOrder();
                this.resize(msg.params.height);
            } else if ('resize' === eventType) {
                this.resize(msg.params.height);
            } else if ('alert' === eventType) {
                msg.params.message = msg.params.message.replace(/<\/?[^>]+>/gi, '');
            }

            this.trigger(eventType, msg.params);
        }

    }
}

XPaymentsWidget.prototype.log = function(msg)
{
    if (this.config.debug) {
        console.log(msg);
    }
}

XPaymentsWidget.prototype.postMessage = function(message)
{
    var elm = this.getIframeElm();
    if (
        window.postMessage
        && window.JSON
        && elm
        && elm.contentWindow
    ) {
        this.log('Sent to X-Payments: ' + message.event + "\n" + window.JSON.stringify(message.params));
        elm.contentWindow.postMessage(window.JSON.stringify(message), '*');
    } else {
        this.log('Error sending message - iframe wasn\'t initialized!');
    }
}
