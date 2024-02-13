(function() {
  "use strict";
  function normalizeComponent(scriptExports, render, staticRenderFns, functionalTemplate, injectStyles, scopeId, moduleIdentifier, shadowMode) {
    var options = typeof scriptExports === "function" ? scriptExports.options : scriptExports;
    if (render) {
      options.render = render;
      options.staticRenderFns = staticRenderFns;
      options._compiled = true;
    }
    if (functionalTemplate) {
      options.functional = true;
    }
    if (scopeId) {
      options._scopeId = "data-v-" + scopeId;
    }
    var hook;
    if (moduleIdentifier) {
      hook = function(context) {
        context = context || // cached call
        this.$vnode && this.$vnode.ssrContext || // stateful
        this.parent && this.parent.$vnode && this.parent.$vnode.ssrContext;
        if (!context && typeof __VUE_SSR_CONTEXT__ !== "undefined") {
          context = __VUE_SSR_CONTEXT__;
        }
        if (injectStyles) {
          injectStyles.call(this, context);
        }
        if (context && context._registeredComponents) {
          context._registeredComponents.add(moduleIdentifier);
        }
      };
      options._ssrRegister = hook;
    } else if (injectStyles) {
      hook = shadowMode ? function() {
        injectStyles.call(
          this,
          (options.functional ? this.parent : this).$root.$options.shadowRoot
        );
      } : injectStyles;
    }
    if (hook) {
      if (options.functional) {
        options._injectStyles = hook;
        var originalRender = options.render;
        options.render = function renderWithStyleInjection(h, context) {
          hook.call(context);
          return originalRender(h, context);
        };
      } else {
        var existing = options.beforeCreate;
        options.beforeCreate = existing ? [].concat(existing, hook) : [hook];
      }
    }
    return {
      exports: scriptExports,
      options
    };
  }
  const _sfc_main = {
    name: "fire",
    data() {
      return {
        isHeatingUp: false,
        index: 0,
        items: []
      };
    },
    created() {
      this.$api.get("fire/pages").then((data) => {
        this.items = data;
      });
    },
    methods: {
      stateText(state) {
        return state.replace(/-/g, " ");
      },
      heatUp(index) {
        this.index = index;
        if (index < this.items.length && this.isHeatingUp === true) {
          this.items[index].state = "fire-up";
          this.$api.post("fire/up", this.items[index]).then((data) => {
            this.$set(this.items, index, data);
            setTimeout(() => {
              this.heatUp(index + 1);
            }, 500);
          });
        }
      },
      start() {
        this.isHeatingUp = true;
        this.heatUp(this.index);
      },
      pause() {
        this.isHeatingUp = false;
      },
      reset() {
        this.isHeatingUp = false;
        this.index = 0;
        this.$api.get("fire/pages").then((data) => {
          this.items = data;
        });
      }
    }
  };
  var _sfc_render = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("k-inside", [_c("k-header", { attrs: { "data-has-buttons": "true" } }, [_c("k-header-title", [_vm._v("Fire up your cache!")]), _c("k-header-buttons", { attrs: { "slot": "buttons" }, slot: "buttons" }, [_c("k-button-group", [!_vm.isHeatingUp && _vm.index === 0 ? _c("k-button", { attrs: { "variant": "filled", "icon": "fire", "theme": "positive" }, on: { "click": function($event) {
      return _vm.start();
    } } }, [_vm._v("Fire up")]) : _vm._e(), !_vm.isHeatingUp && _vm.index !== 0 ? _c("k-button", { attrs: { "variant": "filled", "icon": "fire", "theme": "positive" }, on: { "click": function($event) {
      return _vm.start();
    } } }, [_vm._v("Continue")]) : _vm._e(), !_vm.isHeatingUp && _vm.index !== 0 ? _c("k-button", { attrs: { "variant": "filled", "icon": "cancel", "theme": "negative" }, on: { "click": function($event) {
      return _vm.reset();
    } } }, [_vm._v("Extinguish")]) : _vm._e(), _vm.isHeatingUp ? _c("k-button", { attrs: { "variant": "filled", "icon": "cancel", "theme": "negative" }, on: { "click": function($event) {
      return _vm.pause();
    } } }, [_vm._v("Stop")]) : _vm._e()], 1)], 1)], 1), _c("k-grid", { staticStyle: { "--columns": "1", "gap": "var(--spacing-8)" } }, [_vm.items.length === 0 ? _c("k-empty", { attrs: { "icon": "boiler", "text": "No pages on fire" } }) : _vm._e(), _vm.items.length > 0 ? _c("div", { staticClass: "k-table" }, [_c("table", [_c("thead", [_c("tr", [_c("th", { staticClass: "k-table-index-column" }, [_vm._v("#")]), _c("th", { staticClass: "k-boiler-url", attrs: { "data-mobile": "true" } }, [_vm._v("URL")]), _c("th", { staticClass: "k-state-column", attrs: { "data-mobile": "true" } }, [_vm._v("State")])])]), _c("tbody", _vm._l(_vm.items, function(item, i) {
      return _c("tr", { key: i, ref: "row" + i, refInFor: true }, [_c("td", { staticClass: "k-table-index-column" }, [_c("span", { staticClass: "k-table-index" }, [_vm._v(_vm._s(i + 1))])]), _c("td", { attrs: { "data-mobile": "true" } }, [_c("div", { staticClass: "truncate" }, [_vm._v(_vm._s(item.url))])]), _c("td", { staticClass: "k-state-column", attrs: { "data-mobile": "true" } }, [_c("div", { staticClass: "badge", class: item.state }, [item.state === "no-fire" ? _c("k-icon", { attrs: { "type": "blaze" } }) : _vm._e(), item.state === "fire-up" ? _c("k-icon", { attrs: { "type": "fire" } }) : _vm._e(), item.state === "fire-on" ? _c("k-icon", { attrs: { "type": "fireFilled" } }) : _vm._e(), _c("span", [_vm._v(_vm._s(_vm.stateText(item.state)))])], 1)])]);
    }), 0)])]) : _vm._e()], 1)], 1);
  };
  var _sfc_staticRenderFns = [];
  _sfc_render._withStripped = true;
  var __component__ = /* @__PURE__ */ normalizeComponent(
    _sfc_main,
    _sfc_render,
    _sfc_staticRenderFns,
    false,
    null,
    "95e0715a",
    null,
    null
  );
  __component__.options.__file = "/Users/rafael/Sites/hech/site/plugins/kirby-fire/src/FireView.vue";
  const FireView = __component__.exports;
  panel.plugin("e9li/fire", {
    icons: {
      blaze: '<path d="M19 9C19.6667 10.0606 20 11.3939 20 13C20 16 16.5 17 15 22C14.3333 21.4254 14 20.5921 14 19.5C14 16.0181 19 14.2101 19 9ZM14.5 5C15.1667 6.23841 15.5 7.57175 15.5 9C15.5 14 9.5 15 11.5 22C9.83333 20.8392 9 19.1726 9 17C9 13.675 14.5 11 14.5 5ZM10 1C10.6667 2.33333 11 3.83333 11 5.5C11 11.5 2 13 8 22C5.5 21.5 3.5 19 3.5 16C3.5 9.5 10 8.5 10 1Z"></path>',
      fire: '<path d="M12 23C16.1421 23 19.5 19.6421 19.5 15.5C19.5 14.6345 19.2697 13.8032 19 13.0296C17.3333 14.6765 16.0667 15.5 15.2 15.5C19.1954 8.5 17 5.5 11 1.5C11.5 6.49951 8.20403 8.77375 6.86179 10.0366C5.40786 11.4045 4.5 13.3462 4.5 15.5C4.5 19.6421 7.85786 23 12 23ZM12.7094 5.23498C15.9511 7.98528 15.9666 10.1223 13.463 14.5086C12.702 15.8419 13.6648 17.5 15.2 17.5C15.8884 17.5 16.5841 17.2992 17.3189 16.9051C16.6979 19.262 14.5519 21 12 21C8.96243 21 6.5 18.5376 6.5 15.5C6.5 13.9608 7.13279 12.5276 8.23225 11.4932C8.35826 11.3747 8.99749 10.8081 9.02477 10.7836C9.44862 10.4021 9.7978 10.0663 10.1429 9.69677C11.3733 8.37932 12.2571 6.91631 12.7094 5.23498Z"></path>',
      fireFilled: '<path d="M12 23C7.85786 23 4.5 19.6421 4.5 15.5C4.5 13.3462 5.40786 11.4045 6.86179 10.0366C8.20403 8.77375 11.5 6.49951 11 1.5C17 5.5 20 9.5 14 15.5C15 15.5 16.5 15.5 19 13.0296C19.2697 13.8032 19.5 14.6345 19.5 15.5C19.5 19.6421 16.1421 23 12 23Z"></path>'
    },
    components: {
      fireView: FireView
    }
  });
})();
