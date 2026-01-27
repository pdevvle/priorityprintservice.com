// Claude for Safari - Utility Functions

/**
 * Escape HTML to prevent XSS
 */
export function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/**
 * Format message content with basic markdown support
 */
export function formatMessage(content) {
  if (!content) return '';

  let formatted = escapeHtml(content);

  // Code blocks (must be before inline code)
  formatted = formatted.replace(/```(\w+)?\n([\s\S]*?)```/g, (match, lang, code) => {
    return `<pre><code class="language-${lang || 'plaintext'}">${code.trim()}</code></pre>`;
  });

  // Inline code
  formatted = formatted.replace(/`([^`]+)`/g, '<code>$1</code>');

  // Bold
  formatted = formatted.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

  // Italic
  formatted = formatted.replace(/\*([^*]+)\*/g, '<em>$1</em>');

  // Links
  formatted = formatted.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

  // Unordered lists
  formatted = formatted.replace(/^[\s]*[-*]\s+(.+)$/gm, '<li>$1</li>');
  formatted = formatted.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');

  // Ordered lists
  formatted = formatted.replace(/^[\s]*\d+\.\s+(.+)$/gm, '<li>$1</li>');

  // Headings
  formatted = formatted.replace(/^### (.+)$/gm, '<h4>$1</h4>');
  formatted = formatted.replace(/^## (.+)$/gm, '<h3>$1</h3>');
  formatted = formatted.replace(/^# (.+)$/gm, '<h2>$1</h2>');

  // Line breaks
  formatted = formatted.replace(/\n\n/g, '</p><p>');
  formatted = formatted.replace(/\n/g, '<br>');

  // Wrap in paragraph if not already wrapped
  if (!formatted.startsWith('<')) {
    formatted = `<p>${formatted}</p>`;
  }

  return formatted;
}

/**
 * Truncate text with ellipsis
 */
export function truncate(text, maxLength = 100) {
  if (!text || text.length <= maxLength) return text;
  return text.substring(0, maxLength - 3) + '...';
}

/**
 * Debounce function calls
 */
export function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

/**
 * Throttle function calls
 */
export function throttle(func, limit) {
  let inThrottle;
  return function executedFunction(...args) {
    if (!inThrottle) {
      func(...args);
      inThrottle = true;
      setTimeout(() => (inThrottle = false), limit);
    }
  };
}

/**
 * Generate unique ID
 */
export function generateId() {
  return `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * Format date for display
 */
export function formatDate(date) {
  const d = new Date(date);
  const now = new Date();
  const diff = now - d;

  // Less than a minute
  if (diff < 60000) {
    return 'Just now';
  }

  // Less than an hour
  if (diff < 3600000) {
    const mins = Math.floor(diff / 60000);
    return `${mins}m ago`;
  }

  // Less than a day
  if (diff < 86400000) {
    const hours = Math.floor(diff / 3600000);
    return `${hours}h ago`;
  }

  // Less than a week
  if (diff < 604800000) {
    const days = Math.floor(diff / 86400000);
    return `${days}d ago`;
  }

  // Format as date
  return d.toLocaleDateString();
}

/**
 * Copy text to clipboard
 */
export async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch (error) {
    // Fallback for older browsers
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    const success = document.execCommand('copy');
    document.body.removeChild(textarea);
    return success;
  }
}

/**
 * Parse URL and extract components
 */
export function parseUrl(url) {
  try {
    const parsed = new URL(url);
    return {
      protocol: parsed.protocol,
      hostname: parsed.hostname,
      pathname: parsed.pathname,
      search: parsed.search,
      hash: parsed.hash,
      origin: parsed.origin
    };
  } catch (error) {
    return null;
  }
}

/**
 * Check if URL is valid
 */
export function isValidUrl(string) {
  try {
    new URL(string);
    return true;
  } catch (_) {
    return false;
  }
}

/**
 * Get domain from URL
 */
export function getDomain(url) {
  try {
    return new URL(url).hostname;
  } catch (error) {
    return '';
  }
}

/**
 * Storage helper with error handling
 */
export const storage = {
  async get(keys) {
    try {
      return await browser.storage.local.get(keys);
    } catch (error) {
      console.error('Storage get error:', error);
      return {};
    }
  },

  async set(items) {
    try {
      await browser.storage.local.set(items);
      return true;
    } catch (error) {
      console.error('Storage set error:', error);
      return false;
    }
  },

  async remove(keys) {
    try {
      await browser.storage.local.remove(keys);
      return true;
    } catch (error) {
      console.error('Storage remove error:', error);
      return false;
    }
  },

  async clear() {
    try {
      await browser.storage.local.clear();
      return true;
    } catch (error) {
      console.error('Storage clear error:', error);
      return false;
    }
  }
};

/**
 * Safe JSON parse
 */
export function safeJsonParse(text, fallback = null) {
  try {
    return JSON.parse(text);
  } catch (error) {
    return fallback;
  }
}

/**
 * Convert object to URL search params
 */
export function toSearchParams(obj) {
  return new URLSearchParams(
    Object.entries(obj).filter(([_, v]) => v != null)
  ).toString();
}

/**
 * Sleep/delay function
 */
export function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Retry function with exponential backoff
 */
export async function retry(fn, maxAttempts = 3, baseDelay = 1000) {
  let lastError;

  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    try {
      return await fn();
    } catch (error) {
      lastError = error;
      if (attempt < maxAttempts - 1) {
        const delay = baseDelay * Math.pow(2, attempt);
        await sleep(delay);
      }
    }
  }

  throw lastError;
}

/**
 * Check if running in Safari
 */
export function isSafari() {
  return /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
}

/**
 * Check if running on iOS
 */
export function isIOS() {
  return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}
