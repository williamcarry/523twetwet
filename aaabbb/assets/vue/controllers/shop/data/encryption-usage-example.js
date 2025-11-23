// 加密服务使用示例

import encryptionService from './encryption-service.js';
import axios from 'axios';

/**
 * 用户登录示例 - 加密密码字段
 */
export async function login(email, password) {
    // 准备登录数据，加密密码字段
    const loginData = encryptionService.prepareData(
        { email, password },  // 原始数据
        ['password']          // 需要加密的字段
    );

    try {
        const response = await axios.post('/api/auth/login', loginData);
        return response.data;
    } catch (error) {
        throw new Error(error.response?.data?.message || '登录失败');
    }
}

/**
 * 用户注册示例 - 加密密码字段
 */
export async function register(userData) {
    // 准备注册数据，加密密码字段
    const registerData = encryptionService.prepareData(
        userData,             // 原始数据
        ['password']          // 需要加密的字段
    );

    try {
        const response = await axios.post('/api/auth/register', registerData);
        return response.data;
    } catch (error) {
        throw new Error(error.response?.data?.message || '注册失败');
    }
}

/**
 * 更新用户信息示例 - 不加密任何字段
 */
export async function updateUserInfo(userInfo) {
    // 准备用户信息数据，不加密任何字段
    const updateData = encryptionService.prepareData(
        userInfo,             // 原始数据
        []                    // 不加密任何字段
    );

    try {
        const response = await axios.put('/api/user/profile', updateData);
        return response.data;
    } catch (error) {
        throw new Error(error.response?.data?.message || '更新失败');
    }
}

/**
 * 支付信息示例 - 加密敏感字段
 */
export async function processPayment(paymentData) {
    // 准备支付数据，加密敏感字段
    const encryptedPaymentData = encryptionService.prepareData(
        paymentData,          // 原始数据
        ['cardNumber', 'cvv', 'expiryDate'] // 需要加密的字段
    );

    try {
        const response = await axios.post('/api/payment/process', encryptedPaymentData);
        return response.data;
    } catch (error) {
        throw new Error(error.response?.data?.message || '支付处理失败');
    }
}

/**
 * 普通数据发送示例 - 不加密
 */
export async function sendDataWithoutEncryption(data) {
    // 直接发送原始数据，不进行加密
    const plainData = encryptionService.prepareData(data, []);
    
    try {
        const response = await axios.post('/api/data', plainData);
        return response.data;
    } catch (error) {
        throw new Error(error.response?.data?.message || '数据发送失败');
    }
}

/**
 * 敏感数据发送示例 - 加密指定字段
 */
export async function sendSensitiveData(data, sensitiveFields) {
    // 加密指定的敏感字段
    const encryptedData = encryptionService.prepareData(data, sensitiveFields);
    
    try {
        const response = await axios.post('/api/sensitive-data', encryptedData);
        return response.data;
    } catch (error) {
        throw new Error(error.response?.data?.message || '敏感数据发送失败');
    }
}

// 使用示例：

// 1. 登录（密码会被加密）
// login('user@example.com', 'mySecretPassword');

// 2. 注册（密码会被加密）
// register({
//     email: 'newuser@example.com',
//     password: 'newUserPassword',
//     name: 'New User'
// });

// 3. 更新用户信息（不加密）
// updateUserInfo({
//     name: 'Updated Name',
//     phone: '1234567890'
// });

// 4. 发送普通数据（不加密）
// sendDataWithoutEncryption({
//     message: 'Hello World',
//     timestamp: new Date().toISOString()
// });

// 5. 发送敏感数据（加密指定字段）
// sendSensitiveData({
//     username: 'user123',
//     idCard: '123456789012345678',
//     phone: '13800138000'
// }, ['idCard', 'phone']);