<?php
require_once __DIR__ . '/auth.php';
$chatbot_url = app_path('customer/chatbot.php');

if (
    isset($_SESSION['user_id']) &&
    ($_SESSION['role'] ?? '') === 'customer'
):
?>

<!-- Chatbot Widget -->
<div id="chatbot-container" class="fixed bottom-6 right-6 z-50">
    
    <!-- Chat Bubble Button -->
    <button id="chatbot-toggle" onclick="toggleChat()"
            class="w-14 h-14 bg-red-600 hover:bg-red-700 text-white rounded-full shadow-lg flex items-center justify-center transition-all duration-300 hover:scale-110">
        <svg id="chat-icon" class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
        </svg>
        <svg id="close-icon" class="w-7 h-7 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
    </button>

    <!-- Unread Badge -->
    <span id="chat-badge" class="hidden absolute -top-1 -right-1 w-5 h-5 bg-yellow-400 text-yellow-900 text-xs font-black rounded-full flex items-center justify-center">1</span>

    <!-- Chat Window -->
    <div id="chat-window" class="hidden absolute bottom-16 right-0 w-80 sm:w-96 bg-white rounded-2xl shadow-2xl border border-gray-100 overflow-hidden flex flex-col" style="height: 500px;">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-[#1e2d4a] to-[#2c3e6b] px-4 py-3 flex items-center gap-3">
            <div class="w-9 h-9 bg-red-600 rounded-full flex items-center justify-center flex-shrink-0">
                <span class="text-white text-lg">🤖</span>
            </div>
            <div class="flex-1">
                <p class="text-white font-bold text-sm">MangaBot</p>
                <p class="text-blue-200 text-xs">AI Assistant • Online</p>
            </div>
            <button onclick="clearChat()" class="text-blue-200 hover:text-white transition-colors text-xs">
                Clear
            </button>
            <button onclick="toggleChat()" class="text-blue-200 hover:text-white transition-colors ml-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <!-- Messages -->
        <div id="chat-messages" class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50">
            <!-- Welcome message -->
            <div class="flex items-start gap-2">
                <div class="w-7 h-7 bg-red-600 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                    <span class="text-white text-xs">🤖</span>
                </div>
                <div class="bg-white rounded-2xl rounded-tl-sm px-3 py-2 shadow-sm max-w-[80%]">
                    <p class="text-sm text-gray-700">Hi <?= htmlspecialchars($_SESSION['user_name'] ?? 'there') ?>! 👋 I'm MangaBot, your MangaVault assistant. How can I help you today?</p>
                </div>
            </div>

            <!-- Quick replies -->
            <div class="flex flex-wrap gap-2 pl-9">
                <button onclick="sendQuickReply('What are my recent orders?')" 
                        class="text-xs bg-white border border-gray-200 hover:border-red-400 hover:text-red-600 text-gray-600 px-3 py-1.5 rounded-full transition-colors">
                    📦 My Orders
                </button>
                <button onclick="sendQuickReply('What is my current tier and benefits?')"
                        class="text-xs bg-white border border-gray-200 hover:border-red-400 hover:text-red-600 text-gray-600 px-3 py-1.5 rounded-full transition-colors">
                    🏅 My Tier
                </button>
                <button onclick="sendQuickReply('Recommend me some manga!')"
                        class="text-xs bg-white border border-gray-200 hover:border-red-400 hover:text-red-600 text-gray-600 px-3 py-1.5 rounded-full transition-colors">
                    📚 Recommendations
                </button>
                <button onclick="sendQuickReply('What is the return policy?')"
                        class="text-xs bg-white border border-gray-200 hover:border-red-400 hover:text-red-600 text-gray-600 px-3 py-1.5 rounded-full transition-colors">
                    ↩️ Returns
                </button>
            </div>
        </div>

        <!-- Input -->
        <div class="p-3 bg-white border-t border-gray-100">
            <div class="flex gap-2">
                <input type="text" id="chat-input" 
                       placeholder="Ask me anything..."
                       class="flex-1 px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors"
                       onkeypress="if(event.key==='Enter') sendMessage()">
                <button onclick="sendMessage()" id="send-btn"
                        class="w-9 h-9 bg-red-600 hover:bg-red-700 text-white rounded-xl flex items-center justify-center transition-colors flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                </button>
            </div>
            <p class="text-xs text-gray-400 text-center mt-2">Powered by MangaOwn AI</p>
        </div>
    </div>
</div>

<script>
const chatbotUrl = <?= json_encode($chatbot_url) ?>;

let chatOpen = false;
let isTyping = false;

// Load chat history when page loads
window.addEventListener('DOMContentLoaded', function() {
    fetch(chatbotUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'get_history=1'
    })
    .then(r => r.json())
    .then(data => {
        if (data.history && data.history.length > 0) {
            const messages = document.getElementById('chat-messages');
            // Remove quick replies if there's history
            messages.innerHTML = '';
            // Add welcome message
            messages.innerHTML = `
                <div class="flex items-start gap-2">
                    <div class="w-7 h-7 bg-red-600 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                        <span class="text-white text-xs">🤖</span>
                    </div>
                    <div class="bg-white rounded-2xl rounded-tl-sm px-3 py-2 shadow-sm max-w-[80%]">
                        <p class="text-sm text-gray-700">Hi <?= htmlspecialchars($_SESSION['user_name'] ?? 'there') ?>! 👋 Welcome back! How can I help you?</p>
                    </div>
                </div>`;
            
            data.history.forEach(msg => {
                if (msg.role === 'user') {
                    addMessage(msg.parts[0].text, 'user');
                } else {
                    addMessage(msg.parts[0].text, 'bot');
                }
            });
        }
    })
    .catch(() => {});
});

function toggleChat() {
    chatOpen = !chatOpen;
    const window_ = document.getElementById('chat-window');
    const chatIcon = document.getElementById('chat-icon');
    const closeIcon = document.getElementById('close-icon');
    const badge = document.getElementById('chat-badge');

    if (chatOpen) {
        window_.classList.remove('hidden');
        window_.style.animation = 'slideUp 0.3s ease forwards';
        chatIcon.classList.add('hidden');
        closeIcon.classList.remove('hidden');
        badge.classList.add('hidden');
        document.getElementById('chat-input').focus();
    } else {
        window_.classList.add('hidden');
        chatIcon.classList.remove('hidden');
        closeIcon.classList.add('hidden');
    }
}

function sendQuickReply(text) {
    document.getElementById('chat-input').value = text;
    sendMessage();
}

function sendMessage() {
    const input = document.getElementById('chat-input');
    const message = input.value.trim();
    if (!message || isTyping) return;

    input.value = '';
    addMessage(message, 'user');
    showTyping();
    isTyping = true;

    const sendBtn = document.getElementById('send-btn');
    sendBtn.disabled = true;

   fetch(chatbotUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'message=' + encodeURIComponent(message)
    })
    .then(r => r.json())
    .then(data => {
        hideTyping();
        isTyping = false;
        sendBtn.disabled = false;
        if (data.reply) {
            addMessage(data.reply, 'bot');
        } else {
            addMessage('Sorry, something went wrong. Please try again.', 'bot');
        }
    })
    .catch(() => {
        hideTyping();
        isTyping = false;
        sendBtn.disabled = false;
        addMessage('Connection error. Please try again.', 'bot');
    });
}

function addMessage(text, sender) {
    const messages = document.getElementById('chat-messages');
    const div = document.createElement('div');

    if (sender === 'user') {
        div.className = 'flex justify-end';
        div.innerHTML = `
            <div class="bg-red-600 rounded-2xl rounded-tr-sm px-3 py-2 max-w-[80%]">
                <p class="text-sm text-white">${escapeHtml(text)}</p>
            </div>`;
    } else {
        // Format bot message — convert **text** to bold, newlines to <br>
        const formatted = escapeHtml(text)
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
        div.className = 'flex items-start gap-2';
        div.innerHTML = `
            <div class="w-7 h-7 bg-red-600 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                <span class="text-white text-xs">🤖</span>
            </div>
            <div class="bg-white rounded-2xl rounded-tl-sm px-3 py-2 shadow-sm max-w-[80%]">
                <p class="text-sm text-gray-700">${formatted}</p>
            </div>`;
    }

    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
}

function showTyping() {
    const messages = document.getElementById('chat-messages');
    const div = document.createElement('div');
    div.id = 'typing-indicator';
    div.className = 'flex items-start gap-2';
    div.innerHTML = `
        <div class="w-7 h-7 bg-red-600 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
            <span class="text-white text-xs">🤖</span>
        </div>
        <div class="bg-white rounded-2xl rounded-tl-sm px-3 py-2 shadow-sm">
            <div class="flex gap-1 items-center h-5">
                <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:0s"></span>
                <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:0.15s"></span>
                <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:0.3s"></span>
            </div>
        </div>`;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
}

function hideTyping() {
    const indicator = document.getElementById('typing-indicator');
    if (indicator) indicator.remove();
}

function clearChat() {
    fetch(chatbotUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'clear=1'
    });
    const messages = document.getElementById('chat-messages');
    messages.innerHTML = `
        <div class="flex items-start gap-2">
            <div class="w-7 h-7 bg-red-600 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                <span class="text-white text-xs">🤖</span>
            </div>
            <div class="bg-white rounded-2xl rounded-tl-sm px-3 py-2 shadow-sm max-w-[80%]">
                <p class="text-sm text-gray-700">Chat cleared! How can I help you? 😊</p>
            </div>
        </div>`;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

// Show badge after 3 seconds if chat not opened
setTimeout(() => {
    if (!chatOpen) {
        document.getElementById('chat-badge').classList.remove('hidden');
    }
}, 3000);
</script>

<style>
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<?php endif; ?>