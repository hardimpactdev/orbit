import axios, { AxiosError, type AxiosInstance, type AxiosRequestConfig } from 'axios';
import { toast } from 'vue-sonner';

/**
 * Configured axios instance for API calls.
 *
 * Features:
 * - Centralized error handling with toast notifications
 * - Automatic CSRF token injection from meta tag
 * - Automatic JSON handling
 * - Prepared for future auth token injection
 * - Request cancellation support via AbortController
 */
const api: AxiosInstance = axios.create({
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    timeout: 30000, // 30 second timeout
});

// Set CSRF token from meta tag (Laravel standard)
const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
if (csrfToken) {
    api.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}

// Response interceptor for centralized error handling
api.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
        // Don't show toast for cancelled requests
        if (axios.isCancel(error)) {
            return Promise.reject(error);
        }

        // Handle different error scenarios
        if (error.response) {
            // Server responded with error status
            const status = error.response.status;
            const data = error.response.data as { error?: string; message?: string };
            const message = data?.error || data?.message || 'An error occurred';

            if (status === 401) {
                toast.error('Authentication Error', {
                    description: 'Please check your credentials.',
                });
            } else if (status === 403) {
                toast.error('Access Denied', {
                    description: 'You do not have permission to perform this action.',
                });
            } else if (status === 404) {
                toast.error('Not Found', {
                    description: message,
                });
            } else if (status >= 500) {
                toast.error('Server Error', {
                    description: 'The server encountered an error. Please try again.',
                });
            } else {
                toast.error('Request Failed', {
                    description: message,
                });
            }
        } else if (error.request) {
            // Request made but no response received
            toast.error('Connection Error', {
                description:
                    'Could not connect to the server. Please check if the environment is running.',
            });
        } else {
            // Request setup error
            toast.error('Request Error', {
                description: error.message || 'Failed to make request.',
            });
        }

        return Promise.reject(error);
    },
);

/**
 * Helper to create an AbortController for request cancellation.
 * Usage:
 *   const controller = new AbortController();
 *   api.get('/status', { signal: controller.signal });
 *   controller.abort(); // Cancel the request
 */
export { api };

/**
 * Helper for making API calls with automatic error handling.
 * Returns the response data directly, or null if the request failed.
 *
 * @param config - Axios request config
 * @param showErrors - Whether to show error toasts (default: true)
 * @returns Response data or null on error
 */
export async function apiRequest<T = unknown>(
    config: AxiosRequestConfig,
    showErrors = true,
): Promise<T | null> {
    try {
        const response = await api.request<T>(config);
        return response.data;
    } catch (error) {
        if (!showErrors && !axios.isCancel(error)) {
            // Suppress the toast that was already shown by interceptor
            // by catching here
        }
        return null;
    }
}

export default api;
