import axios from 'axios';

// Default company LAN server fallback
const DEFAULT_HOST = 'http://192.168.100.8:8000/api/v1';
const ENV_API_URL = process.env.EXPO_PUBLIC_API_URL || DEFAULT_HOST;

let currentBaseUrl = ENV_API_URL;

const apiClient = axios.create({
  baseURL: currentBaseUrl,
  timeout: 15000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

/**
 * Robust Server Base URL normalizer.
 * Supports: "192.168.9.200:8080", "http://192.168.9.200:8080", "192.168.9.200:8080/api/v1", etc.
 */
export const setBaseUrl = (url) => {
  if (url && typeof url === 'string') {
    let cleanUrl = url.trim();
    if (!cleanUrl.startsWith('http://') && !cleanUrl.startsWith('https://')) {
      cleanUrl = `http://${cleanUrl}`;
    }
    // Strip trailing /api/v1 or slashes to normalize root
    cleanUrl = cleanUrl.replace(/\/api\/v1\/?$/i, '').replace(/\/+$/, '');
    const finalApiUrl = `${cleanUrl}/api/v1`;
    currentBaseUrl = finalApiUrl;
    apiClient.defaults.baseURL = finalApiUrl;
  }
};

export const getBaseUrl = () => {
  return currentBaseUrl;
};

export const setAuthToken = (token) => {
  if (token) {
    apiClient.defaults.headers.common['Authorization'] = `Bearer ${token}`;
  } else {
    delete apiClient.defaults.headers.common['Authorization'];
  }
};

export default apiClient;
