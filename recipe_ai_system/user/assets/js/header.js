// Get the correct API path
const API_PATH = '../../api/notifications.php';

// Toggle mobile menu
function toggleMobileMenu() {
    const mobileNav = document.getElementById('mobileNav');
    mobileNav.classList.toggle('active');
}

function closeMobileMenu() {
    const mobileNav = document.getElementById('mobileNav');
    mobileNav.classList.remove('active');
}

// Toggle notifications dropdown
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    const userDropdown = document.getElementById('userDropdown');
    
    // Close user dropdown if open
    if (userDropdown) {
        userDropdown.classList.remove('active');
    }
    
    dropdown.classList.toggle('active');
    
    // Close when clicking outside
    if (dropdown.classList.contains('active')) {
        setTimeout(() => {
            document.addEventListener('click', closeNotificationsOnClickOutside);
        }, 10);
    }
}

function closeNotificationsOnClickOutside(e) {
    const dropdown = document.getElementById('notificationDropdown');
    const notificationBtn = document.querySelector('.notification-btn');
    
    if (!dropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
        dropdown.classList.remove('active');
        document.removeEventListener('click', closeNotificationsOnClickOutside);
    }
}

// Toggle user menu dropdown
function toggleUserMenu() {
    const dropdown = document.getElementById('userDropdown');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    // Close notification dropdown if open
    if (notificationDropdown) {
        notificationDropdown.classList.remove('active');
    }
    
    dropdown.classList.toggle('active');
    
    // Close when clicking outside
    if (dropdown.classList.contains('active')) {
        setTimeout(() => {
            document.addEventListener('click', closeUserMenuOnClickOutside);
        }, 10);
    }
}

function closeUserMenuOnClickOutside(e) {
    const dropdown = document.getElementById('userDropdown');
    const userBtn = document.querySelector('.user-profile-btn');
    
    if (!dropdown.contains(e.target) && !userBtn.contains(e.target)) {
        dropdown.classList.remove('active');
        document.removeEventListener('click', closeUserMenuOnClickOutside);
    }
}

// Mark single notification as read
function markAsRead(notificationId) {
    const formData = new FormData();
    formData.append('notification_id', notificationId);
    formData.append('action', 'mark_read');
    
    fetch(API_PATH, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove unread class
            const notifElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
            if (notifElement) {
                notifElement.classList.remove('unread');
            }
            
            // Update badge count
            updateNotificationBadge(data.unread_count);
        }
    })
    .catch(error => console.error('Error marking notification as read:', error));
}

// Mark all notifications as read
function markAllRead() {
    const formData = new FormData();
    formData.append('action', 'mark_all_read');
    
    fetch(API_PATH, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove all unread classes
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
            
            // Update badge count
            updateNotificationBadge(0);
        }
    })
    .catch(error => console.error('Error marking all as read:', error));
}

// Update notification badge with specific count
function updateNotificationBadge(count) {
    const badge = document.querySelector('.notification-badge');
    const notifBtn = document.querySelector('.notification-btn');
    
    if (count > 0) {
        if (badge) {
            badge.textContent = count;
        } else if (notifBtn) {
            // Create badge if it doesn't exist
            const newBadge = document.createElement('span');
            newBadge.className = 'notification-badge';
            newBadge.textContent = count;
            notifBtn.appendChild(newBadge);
        }
    } else {
        if (badge) {
            badge.remove();
        }
    }
}

// Update notification badge count from server
function updateNotificationCount() {
    fetch(API_PATH + '?action=get_count')
        .then(response => response.json())
        .then(data => {
            updateNotificationBadge(data.count);
        })
        .catch(error => console.error('Error fetching notification count:', error));
}

// Theme toggle
function toggleTheme() {
    const html = document.documentElement;
    const themeIcon = document.querySelector('.theme-toggle i');
    
    if (html.getAttribute('data-theme') === 'dark') {
        html.setAttribute('data-theme', 'light');
        themeIcon.classList.remove('fa-sun');
        themeIcon.classList.add('fa-moon');
        localStorage.setItem('theme', 'light');
    } else {
        html.setAttribute('data-theme', 'dark');
        themeIcon.classList.remove('fa-moon');
        themeIcon.classList.add('fa-sun');
        localStorage.setItem('theme', 'dark');
    }
}

// Load saved theme on page load
document.addEventListener('DOMContentLoaded', function() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    const themeIcon = document.querySelector('.theme-toggle i');
    
    if (themeIcon) {
        document.documentElement.setAttribute('data-theme', savedTheme);
        
        if (savedTheme === 'dark') {
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }
    }
    
    // Poll for new notifications every 30 seconds
    setInterval(updateNotificationCount, 30000);
});

// Close dropdowns when pressing Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const notificationDropdown = document.getElementById('notificationDropdown');
        const userDropdown = document.getElementById('userDropdown');
        const mobileNav = document.getElementById('mobileNav');
        
        if (notificationDropdown) notificationDropdown.classList.remove('active');
        if (userDropdown) userDropdown.classList.remove('active');
        if (mobileNav) mobileNav.classList.remove('active');
    }
});