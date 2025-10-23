<!-- Chat Popup Window -->
<div class="chat-popup" id="chatPopup">
    <div class="chat-popup-header">
        <div class="chat-popup-user">
            <div class="chat-popup-avatar" id="chatPopupAvatar">SC</div>
            <div>
                <div class="chat-popup-name" id="chatPopupName">Sarah Chen</div>
                <div class="chat-popup-status" style="font-size: 0.85rem; opacity: 0.9;">Online</div>
            </div>
        </div>
        <button class="chat-popup-close" onclick="closeChatPopup()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="chat-popup-messages" id="chatPopupMessages">
        <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
            <p>Loading messages...</p>
        </div>
    </div>
    <div class="chat-popup-input-area">
        <input type="text" class="chat-popup-input" id="chatPopupInput" placeholder="Type a message..." onkeypress="if(event.key === 'Enter') sendPopupMessage()">
        <button class="chat-popup-send" onclick="sendPopupMessage()">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>
</div>

<script>
let currentChatUserId = null;
let lastMessageId = 0;
let chatPolling = null;

function openChatPopup(name, initials, color, status, userId) {
    currentChatUserId = userId;
    lastMessageId = 0;

    const popup = document.getElementById('chatPopup');
    const avatar = document.getElementById('chatPopupAvatar');
    const nameEl = document.getElementById('chatPopupName');
    const statusEl = document.querySelector('.chat-popup-status');

    avatar.textContent = initials;
    avatar.style.background = color;
    nameEl.textContent = name;
    statusEl.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    popup.classList.add('active');

    // Load messages
    loadChatMessages();

    // Start polling for new messages
    if (chatPolling) clearInterval(chatPolling);
    chatPolling = setInterval(loadNewMessages, 3000); // Poll every 3 seconds
}

function closeChatPopup() {
    const popup = document.getElementById('chatPopup');
    popup.classList.remove('active');
    if (chatPolling) {
        clearInterval(chatPolling);
        chatPolling = null;
    }
    currentChatUserId = null;
    lastMessageId = 0;
}

function loadChatMessages() {
    if (!currentChatUserId) return;

    fetch('../../api/chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_messages&receiver_id=${currentChatUserId}&last_id=0`
    })
    .then(res => res.json())
    .then(data => {
        const container = document.getElementById('chatPopupMessages');
        if (!container) return;

        if (data.messages.length === 0) {
            container.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-secondary);">No messages yet. Start the conversation!</div>';
            return;
        }

        container.innerHTML = data.messages.map(msg => `
            <div class="message ${msg.is_user ? 'user' : ''}">
                <div class="message-avatar" style="background:${msg.color};">${msg.initials}</div>
                <div class="message-content">
                    <p>${msg.message}</p>
                    <small style="opacity:0.7;font-size:0.75rem;">${msg.time}</small>
                </div>
            </div>
        `).join('');

        // Scroll to bottom
        container.scrollTop = container.scrollHeight;

        // Update lastMessageId
        if (data.messages.length > 0) {
            lastMessageId = data.messages[data.messages.length - 1].id;
        }

        // Mark as read
        fetch('../../api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=mark_read&receiver_id=${currentChatUserId}`
        });
    })
    .catch(err => {
        console.error('Load messages error:', err);
    });
}

function loadNewMessages() {
    if (!currentChatUserId || !lastMessageId) return;

    fetch('../../api/chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_messages&receiver_id=${currentChatUserId}&last_id=${lastMessageId}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.messages.length === 0) return;

        const container = document.getElementById('chatPopupMessages');
        data.messages.forEach(msg => {
            const div = document.createElement('div');
            div.className = `message ${msg.is_user ? 'user' : ''}`;
            div.innerHTML = `
                <div class="message-avatar" style="background:${msg.color};">${msg.initials}</div>
                <div class="message-content">
                    <p>${msg.message}</p>
                    <small style="opacity:0.7;font-size:0.75rem;">${msg.time}</small>
                </div>
            `;
            container.appendChild(div);
        });
        container.scrollTop = container.scrollHeight;
        
        // Update lastMessageId to the newest message
        lastMessageId = data.messages[data.messages.length - 1].id;
        
        // Mark new messages as read
        fetch('../../api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=mark_read&receiver_id=${currentChatUserId}`
        });
    });
}

function sendPopupMessage() {
    const input = document.getElementById('chatPopupInput');
    const msg = input.value.trim();
    if (!msg || !currentChatUserId) return;

    // Disable input while sending
    input.disabled = true;

    fetch('../../api/chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=send_message&receiver_id=${currentChatUserId}&message=${encodeURIComponent(msg)}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            input.value = '';
            
            // Add the message immediately to the UI
            const container = document.getElementById('chatPopupMessages');
            const div = document.createElement('div');
            div.className = 'message user';
            div.innerHTML = `
                <div class="message-avatar" style="background:${data.message.color};">${data.message.initials}</div>
                <div class="message-content">
                    <p>${data.message.message}</p>
                    <small style="opacity:0.7;font-size:0.75rem;">${data.message.time}</small>
                </div>
            `;
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
            
            // Update lastMessageId
            lastMessageId = data.message.id;
        } else {
            alert('Failed to send message: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error('Send error:', err);
        alert('Failed to send message.');
    })
    .finally(() => {
        input.disabled = false;
        input.focus();
    });
}
</script>