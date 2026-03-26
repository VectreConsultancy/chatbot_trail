<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dark Red Chatbot Panel</title>
    <style>
        :root {
            --bg-1: #030303;
            --bg-2: #090909;
            --panel: rgba(10, 10, 10, 0.82);
            --panel-border: rgba(255, 50, 50, 0.4);
            --text-main: #f4f4f4;
            --text-soft: #bbbbbb;
            --accent: #ff3b3b;
            --accent-soft: rgba(255, 59, 59, 0.16);
            --assistant-bubble: #191919;
            --user-bubble: #3e0d0d;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at 30% 20%, #151515, transparent 45%),
                        linear-gradient(160deg, var(--bg-1) 0%, var(--bg-2) 60%, #000000 100%);
            color: var(--text-main);
            display: grid;
            place-items: center;
            overflow: hidden;
        }

        .bg-effects {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -1;
            overflow: hidden;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(70px);
            opacity: 0.75;
            background: radial-gradient(circle, rgba(255, 42, 42, 0.65), rgba(255, 42, 42, 0));
            animation: float 14s ease-in-out infinite alternate;
        }

        .orb.one {
            width: 350px;
            height: 350px;
            top: -60px;
            left: -70px;
            animation-duration: 16s;
        }

        .orb.two {
            width: 300px;
            height: 300px;
            bottom: -90px;
            right: -40px;
            animation-duration: 18s;
        }

        .orb.three {
            width: 240px;
            height: 240px;
            top: 40%;
            left: 55%;
            animation-duration: 20s;
        }

        @keyframes float {
            0% {
                transform: translate(0, 0) scale(1);
            }
            100% {
                transform: translate(-35px, 30px) scale(1.12);
            }
        }

        .chat-wrap {
            width: min(92vw, 760px);
            height: min(86vh, 780px);
            border-radius: 24px;
            background: linear-gradient(180deg, rgba(22, 22, 22, 0.9), rgba(8, 8, 8, 0.88));
            border: 1px solid var(--panel-border);
            box-shadow: 0 0 34px rgba(255, 34, 34, 0.2), 0 22px 60px rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(6px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(180deg, rgba(39, 39, 39, 0.46), rgba(9, 9, 9, 0.35));
        }

        .chat-title {
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.4px;
        }

        .status {
            font-size: 0.8rem;
            color: var(--text-soft);
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
            background: var(--accent);
            box-shadow: 0 0 12px rgba(255, 59, 59, 0.8);
        }

        .chat-body {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #4d0e0e transparent;
        }

        .chat-body::-webkit-scrollbar {
            width: 7px;
        }

        .chat-body::-webkit-scrollbar-thumb {
            background: #4d0e0e;
            border-radius: 999px;
        }

        .msg {
            max-width: 78%;
            padding: 11px 14px;
            border-radius: 14px;
            line-height: 1.45;
            font-size: 0.95rem;
            animation: slideIn 0.25s ease;
        }

        .assistant {
            align-self: flex-start;
            background: var(--assistant-bubble);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .user {
            align-self: flex-end;
            background: var(--user-bubble);
            border: 1px solid rgba(255, 100, 100, 0.2);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .chat-input {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px;
            display: flex;
            gap: 10px;
            background: rgba(0, 0, 0, 0.4);
        }

        .chat-input input {
            flex: 1;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(20, 20, 20, 0.95);
            color: var(--text-main);
            border-radius: 12px;
            padding: 12px 14px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .chat-input input::placeholder {
            color: #8b8b8b;
        }

        .chat-input input:focus {
            border-color: rgba(255, 69, 69, 0.8);
            box-shadow: 0 0 0 4px var(--accent-soft);
        }

        .chat-input button {
            border: 1px solid rgba(255, 87, 87, 0.65);
            background: linear-gradient(160deg, #9f1313, #640909);
            color: #fff;
            font-weight: 600;
            border-radius: 12px;
            padding: 0 18px;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.2s ease;
        }

        .chat-input button:hover {
            transform: translateY(-1px);
            box-shadow: 0 0 18px rgba(255, 59, 59, 0.35);
        }

        .chat-input button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .msg.streaming::after {
            content: "▋";
            margin-left: 3px;
            animation: blink 0.8s infinite;
            color: var(--accent);
        }

        @keyframes blink {
            0%, 100% { opacity: 0.2; }
            50% { opacity: 1; }
        }

        @media (max-width: 640px) {
            .chat-wrap {
                height: 100vh;
                width: 100vw;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
    <div class="bg-effects">
        <div class="orb one"></div>
        <div class="orb two"></div>
        <div class="orb three"></div>
    </div>

    <section class="chat-wrap">
        <header class="chat-header">
            <div class="chat-title">Manual AI Assistant</div>
            <div class="status"><span class="dot"></span>Online</div>
        </header>

        <main id="chatBody" class="chat-body">
            <div class="msg assistant">Welcome. I answer from your indexed manual using top-matching chunks for best response.</div>
        </main>

        <form id="chatForm" class="chat-input">
            <input id="chatText" type="text" placeholder="Type your message..." autocomplete="off">
            <button id="sendBtn" type="submit">Send</button>
        </form>
    </section>

    <script>
        const form = document.getElementById('chatForm');
        const input = document.getElementById('chatText');
        const sendBtn = document.getElementById('sendBtn');
        const chatBody = document.getElementById('chatBody');
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        const conversation = [
            { role: 'assistant', content: 'Welcome. I answer from your indexed manual using top-matching chunks for best response.' }
        ];

        const addMessage = (text, role, isStreaming = false) => {
            const bubble = document.createElement('div');
            bubble.className = `msg ${role}`;
            if (isStreaming) bubble.classList.add('streaming');
            bubble.textContent = text;
            chatBody.appendChild(bubble);
            chatBody.scrollTop = chatBody.scrollHeight;
            return bubble;
        };

        const setLoading = (loading) => {
            input.disabled = loading;
            sendBtn.disabled = loading;
        };

        const extractSsePayloads = (buffer) => {
            const payloads = [];
            let boundary = buffer.indexOf('\n\n');

            while (boundary !== -1) {
                const rawEvent = buffer.slice(0, boundary);
                buffer = buffer.slice(boundary + 2);

                const dataLines = rawEvent
                    .split('\n')
                    .filter((line) => line.startsWith('data:'))
                    .map((line) => line.slice(5).trim());

                if (dataLines.length) {
                    payloads.push(dataLines.join('\n'));
                }

                boundary = buffer.indexOf('\n\n');
            }

            return { payloads, buffer };
        };

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const text = input.value.trim();
            if (!text || sendBtn.disabled) return;

            addMessage(text, 'user');
            conversation.push({ role: 'user', content: text });
            input.value = '';
            setLoading(true);

            const assistantBubble = addMessage('', 'assistant', true);
            let assistantText = '';

            try {
                const response = await fetch('{{ route('chat.stream') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'text/event-stream',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ messages: conversation })
                });

                if (!response.ok || !response.body) {
                    const errorPayload = await response.json().catch(() => ({}));
                    throw new Error(errorPayload.message || 'Stream failed');
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let pending = '';

                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;

                    pending += decoder.decode(value, { stream: true });
                    const parsed = extractSsePayloads(pending);
                    pending = parsed.buffer;

                    for (const payload of parsed.payloads) {
                        if (payload === '[DONE]') {
                            continue;
                        }

                        let eventData;
                        try {
                            eventData = JSON.parse(payload);
                        } catch {
                            continue;
                        }

                        if (eventData.type === 'text_delta') {
                            assistantText += eventData.delta;
                            assistantBubble.textContent = assistantText;
                            chatBody.scrollTop = chatBody.scrollHeight;
                        }
                    }
                }

                assistantBubble.classList.remove('streaming');

                if (assistantText.trim() === '') {
                    assistantText = 'No response received. Please try again.';
                    assistantBubble.textContent = assistantText;
                }

                conversation.push({ role: 'assistant', content: assistantText });
            } catch (error) {
                assistantBubble.classList.remove('streaming');
                assistantBubble.textContent = error.message || 'Error connecting to AI. Please verify API key and try again.';
            } finally {
                setLoading(false);
                input.focus();
            }
        });
    </script>
</body>
</html>
