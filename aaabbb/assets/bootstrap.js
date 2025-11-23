// 浏览器兼容性 polyfill - 必须首先加载
import './polyfills.js';

import { startStimulusApp } from '@symfony/stimulus-bridge';
// Vue组件注册已移至各个入口文件（frontend.js/admin.js/supplier.js）
// 不在此处重复注册，避免冲突

const app = startStimulusApp();
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
