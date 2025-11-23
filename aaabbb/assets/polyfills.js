/**
 * 浏览器 Polyfill 脚本
 * 为老旧浏览器提供现代 JavaScript API 支持
 */

// 1. Object.assign polyfill
if (typeof Object.assign !== 'function') {
  Object.defineProperty(Object, 'assign', {
    value: function assign(target) {
      'use strict';
      if (target === null || target === undefined) {
        throw new TypeError('Cannot convert undefined or null to object');
      }
      var to = Object(target);
      for (var index = 1; index < arguments.length; index++) {
        var nextSource = arguments[index];
        if (nextSource !== null && nextSource !== undefined) {
          for (var nextKey in nextSource) {
            if (Object.prototype.hasOwnProperty.call(nextSource, nextKey)) {
              to[nextKey] = nextSource[nextKey];
            }
          }
        }
      }
      return to;
    },
    writable: true,
    configurable: true
  });
}

// 2. Array.from polyfill
if (!Array.from) {
  Array.from = function(arrayLike) {
    if (arrayLike == null) {
      throw new TypeError('Array.from requires an array-like object');
    }
    var arr = [];
    for (var i = 0; i < arrayLike.length; i++) {
      arr.push(arrayLike[i]);
    }
    return arr;
  };
}

// 3. Array.includes polyfill
if (!Array.prototype.includes) {
  Array.prototype.includes = function(searchElement) {
    for (var i = 0; i < this.length; i++) {
      if (this[i] === searchElement) {
        return true;
      }
    }
    return false;
  };
}

// 4. String.includes polyfill
if (!String.prototype.includes) {
  String.prototype.includes = function(search) {
    return this.indexOf(search) !== -1;
  };
}

// 5. String.startsWith polyfill
if (!String.prototype.startsWith) {
  String.prototype.startsWith = function(search) {
    return this.substr(0, search.length) === search;
  };
}

// 6. classList polyfill
if (!('classList' in document.documentElement)) {
  Object.defineProperty(HTMLElement.prototype, 'classList', {
    get: function() {
      var self = this;
      return {
        add: function(className) {
          if (!self.className.match(new RegExp('(^|\\s)' + className + '(\\s|$)'))) {
            self.className += (self.className ? ' ' : '') + className;
          }
        },
        remove: function(className) {
          self.className = self.className.replace(new RegExp('(^|\\s)*' + className + '(\\s|$)*', 'g'), ' ').trim();
        },
        toggle: function(className) {
          if (self.className.match(new RegExp('(^|\\s)' + className + '(\\s|$)'))) {
            self.classList.remove(className);
          } else {
            self.classList.add(className);
          }
        },
        contains: function(className) {
          return !!self.className.match(new RegExp('(^|\\s)' + className + '(\\s|$)'));
        }
      };
    }
  });
}

// 7. 修复 box-sizing
(function() {
  var html = document.documentElement;
  if (html && html.style.boxSizing === '') {
    var style = document.createElement('style');
    style.textContent = '*, *:before, *:after { -moz-box-sizing: border-box; -webkit-box-sizing: border-box; box-sizing: border-box; }';
    document.head.appendChild(style);
  }
})();

// 8. 设置 viewport meta 标签
(function() {
  if (!document.querySelector('meta[name="viewport"]')) {
    var viewportMeta = document.createElement('meta');
    viewportMeta.name = 'viewport';
    viewportMeta.content = 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover';
    document.head.insertBefore(viewportMeta, document.head.firstChild);
  }
})();
