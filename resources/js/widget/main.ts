import { defineCustomElement } from 'vue';
import Widget from './Widget.ce.vue';

/**
 * The chat widget a developer's site loads with one tag:
 * <script src="https://<app>/widget/widget.js" data-key="<the widget's key>" async></script>
 *
 * The widget is a custom element with its own shadow DOM, so the site's styles and the widget's do not meet; it talks
 * to the app that served the script.
 */
const script = (document.currentScript ?? document.querySelector('script[data-key][src*="/widget/widget.js"]')) as HTMLScriptElement | null;
const key = script?.dataset.key;

if (script !== null && key !== undefined && customElements.get('homefinder-widget') === undefined) {
    customElements.define('homefinder-widget', defineCustomElement(Widget));

    const element = document.createElement('homefinder-widget');
    element.setAttribute('origin', new URL(script.src).origin);
    element.setAttribute('channel-key', key);
    document.body.append(element);
}
