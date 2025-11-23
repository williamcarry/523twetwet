import JSEncrypt from 'jsencrypt';
import { RSA_PUBLIC_KEY } from './rsa-public-key.js';

class EncryptionService {
    constructor() {
        this.encrypt = new JSEncrypt();
        this.encrypt.setPublicKey(RSA_PUBLIC_KEY);
    }

    /**
     * 加密单个字段
     * @param {string} data - 要加密的数据
     * @returns {string|null} 加密后的数据，如果加密失败则返回null
     */
    encryptData(data) {
        if (!data) {
            return data;
        }

        try {
            const encrypted = this.encrypt.encrypt(data.toString());
            return encrypted || data; // 如果加密失败，返回原始数据
        } catch (error) {
            console.warn('加密失败，返回原始数据:', error);
            return data;
        }
    }

    /**
     * 加密对象中的指定字段
     * @param {Object} data - 要加密的数据对象
     * @param {Array<string>} fields - 需要加密的字段名数组
     * @returns {Object} 加密后的数据对象
     */
    encryptFields(data, fields) {
        if (!data || !fields || fields.length === 0) {
            return data;
        }

        const encryptedData = { ...data };

        fields.forEach(field => {
            if (encryptedData.hasOwnProperty(field) && encryptedData[field] !== null && encryptedData[field] !== undefined) {
                encryptedData[field] = this.encryptData(encryptedData[field]);
            }
        });

        return encryptedData;
    }

    /**
     * 加密整个JSON对象（更安全）
     * @param {Object} data - 要加密的数据对象
     * @returns {Object} 返回包含加密数据的对象 {encryptedPayload: 'xxx'}
     */
    encryptObject(data) {
        if (!data) {
            return data;
        }

        try {
            // 将对象转为JSON字符串
            const jsonString = JSON.stringify(data);
            
            // RSA加密有长度限制，对于长数据需要分段加密
            const maxLength = 117; // 2048位RSA密钥的最大加密长度
            const chunks = [];
            
            for (let i = 0; i < jsonString.length; i += maxLength) {
                const chunk = jsonString.substring(i, i + maxLength);
                const encryptedChunk = this.encrypt.encrypt(chunk);
                if (!encryptedChunk) {
                    throw new Error('分段加密失败');
                }
                chunks.push(encryptedChunk);
            }
            
            // 将所有加密分段用特殊分隔符连接
            return {
                encryptedPayload: chunks.join('|||')
            };
        } catch (error) {
            console.error('对象加密失败:', error);
            throw error;
        }
    }

    /**
     * 准备发送到后端的数据（可选择是否加密）
     * @param {Object} data - 原始数据
     * @param {Array<string>|boolean} encryptFields - 需要加密的字段名数组，如果为true则加密整个对象，如果为空数组则不加密
     * @returns {Object} 处理后的数据
     */
    prepareData(data, encryptFields = []) {
        // 如果encryptFields为true，加密整个对象
        if (encryptFields === true) {
            return this.encryptObject(data);
        }
        
        // 如果没有指定要加密的字段，直接返回原始数据
        if (!encryptFields || encryptFields.length === 0) {
            return data;
        }
        
        // 否则加密指定字段
        return this.encryptFields(data, encryptFields);
    }
}

// 导出单例实例
export default new EncryptionService();

// 导出类，以便需要多个实例时使用
export { EncryptionService };