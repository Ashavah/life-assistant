<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name') }}</title>
        <script>
            /* Applicato prima del rendering per evitare il lampo di tema chiaro. */
            (function () {
                var stored = null;

                try {
                    stored = window.localStorage.getItem('theme');
                } catch (error) {
                    stored = null;
                }

                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.dataset.theme = stored || (prefersDark ? 'dark' : 'light');
            })();
        </script>
        <style>
            :root {
                color-scheme: light;
                --bg: #f5f5f4; --surface: #fff; --surface-muted: #fafaf9; --surface-hover: #f5f5f4; --surface-hover-strong: #f0eeeb;
                --border: #e7e5e4; --border-strong: #d6d3d1; --border-dashed: #a8a29e;
                --text: #1c1917; --text-secondary: #57534e; --text-muted: #78716c; --text-soft: #a8a29e; --text-contrast: #292524;
                --accent: #1c1917; --accent-text: #fff; --bubble-user: #1c1917; --bubble-user-text: #fff;
                --global-bg: #292524; --global-text: #fff; --global-active-border: #0c0a09;
                --badge-bg: rgb(120 113 108 / .12); --topbar-bg: rgb(255 255 255 / .85);
                --shadow-soft: rgb(28 25 23 / .06); --shadow-menu: rgb(28 25 23 / .13); --shadow-panel: rgb(28 25 23 / .15); --overlay: rgb(28 25 23 / .35);
                --success-bg: #f0fdf4; --success-text: #166534; --notice-bg: #ecfdf5;
                --danger-bg: #fef2f2; --danger-text: #b91c1c; --danger-border: #fecaca;
                --warning-bg: #fffbeb; --warning-border: #fde68a; --warning-text: #92400e;
                --info-bg: #eff6ff; --info-border: #bfdbfe; --info-text: #475569;
                --link: #1d4ed8; --code-bg: #f5f5f4; --pre-bg: #1c1917; --pre-text: #fafaf9;
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                color: var(--text);
                background: var(--bg);
            }
            [data-theme="dark"] {
                color-scheme: dark;
                --bg: #0c0a09; --surface: #1c1917; --surface-muted: #171412; --surface-hover: #292524; --surface-hover-strong: #292524;
                --border: #292524; --border-strong: #44403c; --border-dashed: #57534e;
                --text: #f5f5f4; --text-secondary: #d6d3d1; --text-muted: #a8a29e; --text-soft: #78716c; --text-contrast: #e7e5e4;
                --accent: #e7e5e4; --accent-text: #1c1917; --bubble-user: #33302c; --bubble-user-text: #fafaf9;
                --global-bg: #3f3a35; --global-text: #fafaf9; --global-active-border: #a8a29e;
                --badge-bg: rgb(214 211 209 / .16); --topbar-bg: rgb(28 25 23 / .85);
                --shadow-soft: rgb(0 0 0 / .4); --shadow-menu: rgb(0 0 0 / .55); --shadow-panel: rgb(0 0 0 / .6); --overlay: rgb(0 0 0 / .6);
                --success-bg: rgb(22 101 52 / .22); --success-text: #86efac; --notice-bg: rgb(22 101 52 / .22);
                --danger-bg: rgb(127 29 29 / .25); --danger-text: #fca5a5; --danger-border: #7f1d1d;
                --warning-bg: rgb(120 53 15 / .3); --warning-border: #78350f; --warning-text: #fcd34d;
                --info-bg: rgb(30 58 138 / .25); --info-border: #1e40af; --info-text: #cbd5e1;
                --link: #93c5fd; --code-bg: #292524; --pre-bg: #0c0a09; --pre-text: #fafaf9;
            }
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; }
            button, textarea, input { font: inherit; }
            button { cursor: pointer; }
            .app { display: grid; grid-template-columns: 18rem minmax(0, 1fr); min-height: 100dvh; }
            .sidebar { border-right: 1px solid var(--border); background: var(--surface-muted); padding: 1rem; overflow-y: auto; }
            .sidebar-backdrop { display: none; }
            .brand { padding: .5rem .65rem 1.25rem; }
            .brand h1 { margin: 0; font-size: 1rem; }
            .brand p { margin: .25rem 0 0; color: var(--text-muted); font-size: .78rem; }
            .characters { display: flex; flex-direction: column; gap: .45rem; }
            .character-link { display: block; border: 1px solid transparent; border-radius: .8rem; padding: .7rem; color: inherit; text-decoration: none; }
            .character-link:hover { background: var(--surface-hover-strong); }
            .character-link.active { border-color: var(--border-strong); background: var(--surface); box-shadow: 0 1px 2px rgb(0 0 0 / .04); }
            .character-link.global { background: var(--global-bg); color: var(--global-text); }
            .character-link.global.active { border-color: var(--global-active-border); }
            .character-title { display: flex; justify-content: space-between; align-items: center; gap: .5rem; font-size: .9rem; font-weight: 650; }
            .character-description { display: block; margin-top: .18rem; opacity: .65; font-size: .75rem; }
            .memory-count { border-radius: 999px; background: var(--badge-bg); padding: .1rem .4rem; font-size: .68rem; font-weight: 600; }
            .conversations { display: flex; flex-direction: column; gap: .25rem; margin: .45rem 0 .4rem .75rem; padding-left: .55rem; border-left: 1px solid var(--border-strong); }
            .conversation-link { overflow: hidden; padding: .35rem .45rem; border-radius: .45rem; color: var(--text-secondary); font-size: .76rem; text-decoration: none; text-overflow: ellipsis; white-space: nowrap; }
            .conversation-link:hover, .conversation-link.active { background: var(--border); color: var(--text); }
            .conversation-link.closed { opacity: .6; }
            .new-chat { width: 100%; margin-top: .65rem; border: 1px dashed var(--border-dashed); border-radius: .65rem; background: transparent; padding: .55rem; color: var(--text-secondary); font-size: .8rem; }
            .new-chat:hover { background: var(--surface); }
            .main { min-width: 0; height: 100dvh; display: flex; flex-direction: column; }
            .topbar { display: flex; align-items: center; gap: .75rem; border-bottom: 1px solid var(--border); background: var(--topbar-bg); padding: .85rem 1.25rem; }
            .identity { min-width: 0; flex: 1; }
            .identity h2 { overflow: hidden; margin: 0; font-size: 1rem; text-overflow: ellipsis; white-space: nowrap; }
            .identity p { overflow: hidden; margin: .2rem 0 0; color: var(--text-muted); font-size: .78rem; text-overflow: ellipsis; white-space: nowrap; }
            .actions { display: flex; align-items: center; gap: .4rem; }
            .icon-button { display: inline-flex; align-items: center; justify-content: center; width: 2.15rem; height: 2.15rem; flex: none; border: 1px solid var(--border-strong); border-radius: .65rem; background: var(--surface); padding: 0; color: var(--text-secondary); font-size: 1rem; line-height: 1; }
            .icon-button:hover { background: var(--surface-hover); }
            .menu-toggle { display: none; }
            .avatar-button { display: inline-flex; align-items: center; justify-content: center; width: 2.15rem; height: 2.15rem; flex: none; border: 0; border-radius: 999px; background: var(--accent); padding: 0; color: var(--accent-text); font-size: .72rem; font-weight: 700; letter-spacing: .02em; }
            .menu { position: relative; }
            .menu-panel { position: absolute; right: 0; top: calc(100% + .4rem); z-index: 15; display: grid; min-width: 14rem; gap: .1rem; border: 1px solid var(--border); border-radius: .8rem; background: var(--surface); padding: .35rem; box-shadow: 0 14px 30px var(--shadow-menu); }
            .menu-panel[hidden] { display: none; }
            .menu-item { display: block; width: 100%; border: 0; border-radius: .55rem; background: transparent; padding: .55rem .6rem; color: var(--text-contrast); font-size: .8rem; text-align: left; text-decoration: none; }
            .menu-item:hover { background: var(--surface-hover); }
            .menu-item.danger { border: 0; color: var(--danger-text); }
            .menu-item.toggle { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
            .switch { position: relative; width: 2.1rem; height: 1.2rem; flex: none; border-radius: 999px; background: var(--border-strong); transition: background .18s ease; }
            .switch::after { content: ''; position: absolute; top: .15rem; left: .15rem; width: .9rem; height: .9rem; border-radius: 999px; background: var(--surface); transition: transform .18s ease; }
            .menu-item[aria-checked="true"] .switch { background: #16a34a; }
            .menu-item[aria-checked="true"] .switch::after { transform: translateX(.9rem); }
            .menu-heading { padding: .45rem .6rem .35rem; color: var(--text-soft); font-size: .7rem; overflow: hidden; text-overflow: ellipsis; }
            .menu-heading strong { display: block; color: var(--text); font-size: .82rem; }
            .menu-separator { margin: .3rem .35rem; border-top: 1px solid var(--border); }
            .status-badge { border-radius: 999px; background: var(--success-bg); color: var(--success-text); padding: .25rem .55rem; font-size: .7rem; font-weight: 600; }
            .status-badge.closed { background: var(--surface-hover); color: var(--text-muted); }
            .secondary { border: 1px solid var(--border-strong); border-radius: .65rem; background: var(--surface); padding: .45rem .7rem; color: var(--text-secondary); font-size: .78rem; }
            .secondary:hover { background: var(--surface-hover); }
            .content { width: min(100%, 72rem); min-height: 0; flex: 1; margin: 0 auto; padding: 1rem 1.5rem; display: flex; flex-direction: column; }
            .global-note { margin-bottom: .75rem; border: 1px solid var(--warning-border); border-radius: .75rem; background: var(--warning-bg); padding: .65rem .8rem; color: var(--warning-text); font-size: .78rem; line-height: 1.4; }
            .messages { min-height: 0; flex: 1; display: flex; flex-direction: column; gap: .75rem; overflow-y: auto; padding: .25rem; }
            .message { max-width: min(82%, 50rem); border-radius: 1rem; padding: .75rem 1rem; font-size: .93rem; line-height: 1.55; white-space: pre-wrap; overflow-wrap: anywhere; }
            .message.user { margin-left: auto; background: var(--bubble-user); color: var(--bubble-user-text); border-bottom-right-radius: .25rem; }
            .message.assistant { align-self: flex-start; border: 1px solid var(--border); background: var(--surface); border-bottom-left-radius: .25rem; }
            .rich-text { white-space: normal; }
            .rich-text > :first-child { margin-top: 0; }
            .rich-text > :last-child { margin-bottom: 0; }
            .rich-text p { margin: .55rem 0; }
            .rich-text ul, .rich-text ol { margin: .55rem 0; padding-left: 1.15rem; }
            .rich-text li { margin: .2rem 0; }
            .rich-text li::marker { color: var(--text-soft); }
            .rich-text li > ul, .rich-text li > ol { margin: .2rem 0; }
            .rich-text h1, .rich-text h2, .rich-text h3, .rich-text h4 { margin: .9rem 0 .4rem; font-size: .95rem; font-weight: 700; line-height: 1.35; }
            .rich-text strong { font-weight: 650; }
            .rich-text a { color: var(--link); text-decoration: underline; text-underline-offset: 2px; }
            .rich-text code { border-radius: .35rem; background: var(--code-bg); padding: .1rem .3rem; font-size: .85em; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
            .rich-text pre { overflow-x: auto; margin: .6rem 0; border-radius: .6rem; background: var(--pre-bg); padding: .7rem .8rem; }
            .rich-text pre code { background: none; padding: 0; color: var(--pre-text); }
            .rich-text blockquote { margin: .6rem 0; border-left: 3px solid var(--border); padding: .1rem 0 .1rem .7rem; color: var(--text-secondary); }
            .rich-text hr { margin: .8rem 0; border: 0; border-top: 1px solid var(--border); }
            .rich-text table { display: block; overflow-x: auto; margin: .6rem 0; border-collapse: collapse; font-size: .85rem; }
            .rich-text th, .rich-text td { border: 1px solid var(--border); padding: .35rem .55rem; text-align: left; }
            .rich-text th { background: var(--surface-muted); font-weight: 650; }
            .empty { margin: auto; max-width: 26rem; color: var(--text-soft); text-align: center; font-size: .9rem; line-height: 1.55; }
            .feedback { min-height: 1.2rem; margin: .4rem .2rem; color: var(--success-text); font-size: .78rem; }
            .feedback.error { color: var(--danger-text); }
            .composer { display: flex; align-items: flex-end; gap: .6rem; border: 1px solid var(--border-strong); border-radius: 1rem; background: var(--surface); padding: .5rem; box-shadow: 0 8px 24px var(--shadow-soft); }
            .composer textarea { min-height: 2.8rem; max-height: 16rem; flex: 1; overflow-y: auto; resize: none; border: 0; outline: 0; background: transparent; padding: .6rem; color: var(--text); font: inherit; line-height: 1.5; }
            .send { display: inline-flex; align-items: center; justify-content: center; height: 2.8rem; flex: none; border: 0; border-radius: .75rem; background: var(--accent); padding: 0 1.1rem; color: var(--accent-text); font-size: .82rem; font-weight: 600; }
            .send:disabled, button:disabled { cursor: not-allowed; opacity: .45; }
            .proposal { align-self: flex-start; width: min(100%, 34rem); border: 1px solid var(--info-border); border-radius: .85rem; background: var(--info-bg); padding: .8rem; }
            .proposal h4 { margin: 0 0 .35rem; font-size: .9rem; }
            .proposal p { margin: .2rem 0; color: var(--info-text); font-size: .78rem; line-height: 1.45; }
            .proposal-actions { display: flex; gap: .45rem; margin-top: .65rem; }
            .proposal-status { font-size: .72rem; font-weight: 700; text-transform: uppercase; }
            .danger { border-color: var(--danger-border); color: var(--danger-text); }
            .notice { margin: .5rem 1.25rem 0; border-radius: .6rem; padding: .55rem .7rem; background: var(--notice-bg); color: var(--success-text); font-size: .78rem; }
            .notice.error { background: var(--danger-bg); color: var(--danger-text); }
            .settings-backdrop { position: fixed; inset: 0; z-index: 20; display: grid; justify-items: end; background: var(--overlay); }
            .settings-backdrop[hidden] { display: none; }
            .settings-panel { width: min(100%, 34rem); height: 100%; overflow-y: auto; background: var(--surface); padding: 1.25rem; box-shadow: -12px 0 32px var(--shadow-panel); }
            .settings-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
            .settings-header h3 { margin: 0; }
            .settings-form { display: grid; gap: .9rem; margin-top: 1rem; }
            .settings-form label { display: grid; gap: .35rem; color: var(--text-secondary); font-size: .78rem; font-weight: 650; }
            .settings-form input, .settings-form textarea, .settings-form select { width: 100%; border: 1px solid var(--border-strong); border-radius: .65rem; background: var(--surface); padding: .65rem; color: var(--text); resize: vertical; }
            .knowledge-textarea { resize: none; min-height: 7rem; max-height: 18rem; overflow-y: auto; line-height: 1.5; }
            .integration-card { margin-top: 1.25rem; border-top: 1px solid var(--border); padding-top: 1rem; }
            .integration-card h4 { margin: 0 0 .35rem; }
            .integration-card p { color: var(--text-muted); font-size: .8rem; line-height: 1.45; }
            .integration-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
            .knowledge-card { margin-top: .75rem; border: 1px solid var(--border); border-radius: .75rem; background: var(--surface-muted); padding: .75rem; }
            .knowledge-card-header { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
            .knowledge-card-header h5 { overflow: hidden; margin: 0; font-size: .82rem; text-overflow: ellipsis; white-space: nowrap; }
            .knowledge-status { border-radius: 999px; background: var(--border); padding: .18rem .45rem; color: var(--text-secondary); font-size: .65rem; font-weight: 700; text-transform: uppercase; }
            .knowledge-group { display: grid; gap: .45rem; margin-top: .75rem; }
            .knowledge-group h6 { margin: 0; color: var(--text-secondary); font-size: .72rem; text-transform: uppercase; }
            .knowledge-item { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: .55rem; border: 1px solid var(--border); border-radius: .6rem; background: var(--surface); padding: .6rem; }
            .knowledge-item input { width: auto; margin-top: .2rem; }
            .knowledge-item p { margin: 0; color: var(--text-contrast); font-size: .78rem; line-height: 1.4; }
            .knowledge-meta { display: block; margin-top: .25rem; color: var(--text-muted); font-size: .68rem; }
            .knowledge-actions { display: flex; gap: .45rem; margin-top: .75rem; }
            .knowledge-empty { margin: .6rem 0 0; color: var(--text-muted); font-size: .76rem; }
            .no-conversation { margin: auto; text-align: center; }
            .no-conversation h3 { margin: 0 0 .35rem; }
            .no-conversation p { margin: 0 0 1rem; color: var(--text-muted); font-size: .86rem; }
            .primary { display: inline-block; border: 0; border-radius: .75rem; background: var(--accent); padding: .65rem 1rem; color: var(--accent-text); font-weight: 600; text-decoration: none; }
            @media (max-width: 860px) {
                .app { grid-template-columns: minmax(0, 1fr); }
                .sidebar { position: fixed; z-index: 30; top: 0; bottom: 0; left: 0; width: min(86vw, 19rem); transform: translateX(-100%); transition: transform .22s ease; }
                .sidebar.open { transform: none; }
                .sidebar-backdrop { position: fixed; inset: 0; z-index: 25; display: block; background: var(--overlay); }
                .sidebar-backdrop[hidden] { display: none; }
                .menu-toggle { display: inline-flex; }
                .topbar { padding: .7rem .8rem; }
                .topbar .status-badge { display: none; }
                .content { padding: .65rem; }
                .message { max-width: 92%; }
                .settings-panel { padding: 1rem; }
            }
            @media (prefers-reduced-motion: reduce) {
                .sidebar { transition: none; }
            }
        </style>
    </head>
    <body>
        <div class="app">
            <div class="sidebar-backdrop" id="sidebar-backdrop" hidden></div>

            <aside class="sidebar" id="sidebar">
                <div class="brand">
                    <h1>{{ config('app.name') }}</h1>
                    <p>Assistenti separati, memoria persistente.</p>
                </div>

                <nav class="characters" aria-label="Personaggi">
                    @foreach ($characters as $character)
                        <section>
                            <a
                                href="{{ route('home', ['character' => $character->slug]) }}"
                                class="character-link {{ $character->is_global ? 'global' : '' }} {{ $selectedCharacter?->is($character) ? 'active' : '' }}"
                            >
                                <span class="character-title">
                                    {{ $character->name }}
                                    @unless ($character->is_global)
                                        <span class="memory-count">{{ $character->active_memories_count }}</span>
                                    @endunless
                                </span>
                                <span class="character-description">{{ $character->description }}</span>
                            </a>

                            @if ($selectedCharacter?->is($character))
                                <div class="conversations">
                                    @forelse ($character->conversations as $conversation)
                                        <a
                                            href="{{ route('home', ['character' => $character->slug, 'conversation' => $conversation->id]) }}"
                                            class="conversation-link {{ $selectedConversation?->is($conversation) ? 'active' : '' }} {{ $conversation->isActive() ? '' : 'closed' }}"
                                            title="{{ $conversation->title ?? 'Nuova conversazione' }}"
                                            data-current-conversation-title="{{ $selectedConversation?->is($conversation) ? 'true' : 'false' }}"
                                        >
                                            {{ $conversation->isActive() ? '●' : '○' }}
                                            {{ $conversation->title ?? 'Nuova conversazione' }}
                                        </a>
                                    @empty
                                        <span class="conversation-link closed">Nessuna conversazione</span>
                                    @endforelse
                                </div>
                                <button class="new-chat" type="button" data-new-character="{{ $character->id }}">+ Nuova chat</button>
                            @endif
                        </section>
                    @endforeach
                </nav>

                <details class="integration-card">
                    <summary>+ Nuovo specialista</summary>
                    <form class="settings-form" id="create-character-form">
                        <label>Nome
                            <input name="name" required maxlength="255" placeholder="es. Personal trainer">
                        </label>
                        <label>Definizione e ambito
                            <textarea name="description" rows="3" required maxlength="255" placeholder="Di cosa si occupa"></textarea>
                        </label>
                        <label>Istruzioni personalizzate
                            <textarea name="system_prompt" rows="4" maxlength="12000" placeholder="Facoltative: verrà creato un prompt di base"></textarea>
                        </label>
                        <label>Tono
                            <textarea name="tone" rows="2" maxlength="1000" placeholder="Facoltativo"></textarea>
                        </label>
                        <p class="feedback" id="create-character-feedback"></p>
                        <button type="submit" class="primary">Crea specialista</button>
                    </form>
                </details>
            </aside>

            <main class="main">
                @if ($selectedCharacter)
                    <header class="topbar">
                        <button
                            type="button"
                            class="icon-button menu-toggle"
                            id="sidebar-toggle"
                            aria-controls="sidebar"
                            aria-expanded="false"
                            aria-label="Apri l’elenco dei personaggi"
                        >☰</button>

                        <div class="identity">
                            <h2>{{ $selectedCharacter->name }}</h2>
                            <p id="conversation-title">{{ $selectedConversation?->title ?? $selectedCharacter->description }}</p>
                        </div>

                        <div class="actions">
                            @if ($selectedConversation)
                                <span class="status-badge {{ $selectedConversation->isActive() ? '' : 'closed' }}">
                                    {{ $selectedConversation->isActive() ? 'Attiva' : 'Chiusa' }}
                                </span>
                            @endif

                            <div class="menu" data-menu>
                                <button
                                    type="button"
                                    class="icon-button"
                                    data-menu-trigger
                                    aria-haspopup="true"
                                    aria-expanded="false"
                                    aria-label="Azioni della chat"
                                >⋯</button>
                                <div class="menu-panel" data-menu-panel hidden>
                                    @if ($selectedConversation)
                                        @if ($selectedConversation->isActive())
                                            <button type="button" class="menu-item" id="close-chat">Chiudi e salva memoria</button>
                                        @endif
                                        <button
                                            type="button"
                                            class="menu-item danger"
                                            id="discard-chat"
                                            data-discard-active="{{ $selectedConversation->isActive() ? '1' : '0' }}"
                                        >
                                            {{ $selectedConversation->isActive() ? 'Chiudi e elimina' : 'Elimina chat' }}
                                        </button>
                                        <div class="menu-separator"></div>
                                    @endif
                                    <button type="button" class="menu-item" id="open-settings">
                                        {{ $selectedCharacter->is_global ? 'Importa conoscenze' : 'Impostazioni di '.$selectedCharacter->name }}
                                    </button>
                                </div>
                            </div>

                            <div class="menu" data-menu>
                                <button
                                    type="button"
                                    class="avatar-button"
                                    data-menu-trigger
                                    aria-haspopup="true"
                                    aria-expanded="false"
                                    aria-label="Il mio account"
                                >{{ auth()->user()->initials() }}</button>
                                <div class="menu-panel" data-menu-panel hidden>
                                    <div class="menu-heading">
                                        <strong>{{ auth()->user()->name }}</strong>
                                        {{ auth()->user()->email }}
                                    </div>
                                    <button type="button" class="menu-item toggle" id="theme-toggle" role="switch" aria-checked="false" data-keep-open>
                                        <span>Tema scuro</span>
                                        <span class="switch" aria-hidden="true"></span>
                                    </button>
                                    <button type="button" class="menu-item" id="open-account-settings">Il mio account</button>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="menu-item danger">Esci</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </header>

                    @if (session('status'))
                        <p class="notice">{{ session('status') }}</p>
                    @endif
                    @if (session('error'))
                        <p class="notice error">{{ session('error') }}</p>
                    @endif

                    <div class="content">
                        @if ($selectedCharacter->is_global)
                            <div class="global-note">
                                Questa chat ha una memoria propria e vede le memorie di tutti gli specialisti. Solo il Globale può unire o distribuire i fatti tra di loro.
                            </div>
                        @endif

                        @if ($selectedConversation)
                            <div class="messages" id="messages">
                                @if (! $selectedConversation->isActive() && filled($selectedConversation->summary))
                                    <article class="message assistant rich-text">@markdown($selectedConversation->summary)</article>
                                @else
                                    @forelse ($selectedConversation->messages as $message)
                                        @if ($message->role === 'assistant')
                                            <article class="message assistant rich-text">@markdown($message->content)</article>
                                        @else
                                            <article class="message user">{{ $message->content }}</article>
                                        @endif
                                        @foreach ($selectedConversation->externalActionProposals->where('source_message_id', $message->id) as $proposal)
                                            <section
                                                class="proposal"
                                                data-proposal
                                                data-confirm-url="{{ route('external-actions.confirm', $proposal) }}"
                                                data-reject-url="{{ route('external-actions.reject', $proposal) }}"
                                            >
                                                <span class="proposal-status">{{ $proposal->status->value }}</span>
                                                <h4>{{ $proposal->type->title($proposal->payload) }}</h4>
                                                @if (str_contains($proposal->type->value, 'calendar.') || str_contains($proposal->type->value, 'create_event'))
                                                    <p>{{ data_get($proposal->payload, 'start') }} → {{ data_get($proposal->payload, 'end') }}</p>
                                                    @if (data_get($proposal->payload, 'location'))
                                                        <p>Luogo: {{ data_get($proposal->payload, 'location') }}</p>
                                                    @endif
                                                @elseif (str_contains($proposal->type->value, 'mail.'))
                                                    <p>A: {{ implode(', ', data_get($proposal->payload, 'to', [])) }}</p>
                                                    <p>{{ data_get($proposal->payload, 'body') }}</p>
                                                @elseif (data_get($proposal->payload, 'content') || data_get($proposal->payload, 'text') || data_get($proposal->payload, 'body'))
                                                    <p>{{ data_get($proposal->payload, 'content', data_get($proposal->payload, 'text', data_get($proposal->payload, 'body'))) }}</p>
                                                @endif
                                                @if (in_array($proposal->status->value, ['pending', 'failed'], true))
                                                    <div class="proposal-actions">
                                                        <button type="button" class="primary" data-confirm-proposal>Conferma</button>
                                                        <button type="button" class="secondary danger" data-reject-proposal>Rifiuta</button>
                                                    </div>
                                                @elseif (data_get($proposal->result, 'html_link') || data_get($proposal->result, 'web_link'))
                                                    <p><a href="{{ data_get($proposal->result, 'html_link', data_get($proposal->result, 'web_link')) }}" target="_blank" rel="noopener">Apri su {{ $proposal->type->providerLabel() }}</a></p>
                                                @endif
                                            </section>
                                        @endforeach
                                    @empty
                                        <p class="empty" id="empty-state">Inizia questa conversazione con {{ $selectedCharacter->name }}.</p>
                                    @endforelse
                                @endif
                            </div>

                            <p class="feedback" id="feedback"></p>

                            @if ($selectedConversation->isActive())
                                <form class="composer" id="chat-form">
                                    <textarea id="message" name="message" rows="1" required maxlength="4000" placeholder="Scrivi a {{ $selectedCharacter->name }}…"></textarea>
                                    <button type="submit" class="send" id="send-button">Invia</button>
                                </form>
                            @else
                                <p class="empty">Questa conversazione è chiusa. I messaggi sono stati sostituiti dal riepilogo consolidato.</p>
                            @endif
                        @else
                            <div class="no-conversation">
                                <h3>Nessuna chat aperta</h3>
                                <p>Crea una conversazione separata con {{ $selectedCharacter->name }}.</p>
                                <button class="primary" type="button" data-new-character="{{ $selectedCharacter->id }}">Nuova chat</button>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="no-conversation">
                        <h3>Assistenti non disponibili</h3>
                        <p>Non è stato possibile preparare i personaggi del tuo account.</p>
                    </div>
                @endif
            </main>
        </div>

        @if ($selectedCharacter)
            <div class="settings-backdrop" id="settings-backdrop" @if (! $settingsOpen) hidden @endif>
                <aside class="settings-panel" role="dialog" aria-modal="true" aria-labelledby="settings-title">
                    <div class="settings-header">
                        <h3 id="settings-title">
                            {{ $selectedCharacter->is_global ? 'Conoscenze globali' : 'Impostazioni di '.$selectedCharacter->name }}
                        </h3>
                        <button type="button" class="secondary" id="close-settings">Chiudi</button>
                    </div>

                    @unless ($selectedCharacter->is_global)
                        <form class="settings-form" id="settings-form">
                            <label>Nome
                                <input name="name" value="{{ $selectedCharacter->name }}" required maxlength="255">
                            </label>
                            <label>Descrizione e ambito
                                <textarea name="description" rows="3" required maxlength="255">{{ $selectedCharacter->description }}</textarea>
                            </label>
                            <label>System prompt
                                <textarea name="system_prompt" rows="10" required maxlength="12000">{{ $selectedCharacter->system_prompt }}</textarea>
                            </label>
                            <label>Tono
                                <textarea name="tone" rows="3" required maxlength="1000">{{ $selectedCharacter->tone }}</textarea>
                            </label>
                            <p class="feedback" id="settings-feedback"></p>
                            <button type="submit" class="primary" id="save-settings">Salva impostazioni</button>
                        </form>
                    @endunless

                    <section class="integration-card">
                        <h4>{{ $selectedCharacter->is_global ? 'Importa e smista conoscenze' : 'Importa conoscenze' }}</h4>
                        <p>
                            Incolla testo o carica TXT, Markdown, PDF, DOCX e immagini.
                            Nulla entra nella memoria finché non confermi l’anteprima.
                            @if ($selectedCharacter->is_global)
                                Il Globale è gestito dal sistema e non è personalizzabile. Analizzerà il contenuto e distribuirà i fatti soltanto agli specialisti pertinenti, creando sintesi proprie solo quando realmente trasversali.
                            @else
                                {{ $selectedCharacter->name }} ignorerà i fatti estranei al proprio ambito.
                            @endif
                        </p>
                        <form class="settings-form" id="knowledge-form" enctype="multipart/form-data">
                            <label>Testo da assimilare
                                <textarea class="knowledge-textarea" name="text" rows="5" maxlength="{{ config('knowledge.max_text_characters') }}" placeholder="Incolla qui informazioni, note o contesto…"></textarea>
                            </label>
                            <label>File
                                <input
                                    type="file"
                                    name="files[]"
                                    multiple
                                    accept=".txt,.md,.pdf,.docx,.jpg,.jpeg,.png,.webp"
                                >
                            </label>
                            <p class="feedback" id="knowledge-feedback"></p>
                            <button type="submit" class="primary">Analizza e prepara anteprima</button>
                        </form>

                        <div id="knowledge-list">
                            @foreach ($knowledgeIngestions as $ingestion)
                                <article
                                    class="knowledge-card"
                                    data-knowledge-ingestion
                                    data-status="{{ $ingestion->status->value }}"
                                    data-item-count="{{ $ingestion->item_count }}"
                                    data-status-url="{{ route('knowledge-ingestions.show', $ingestion) }}"
                                    data-confirm-url="{{ route('knowledge-ingestions.confirm', $ingestion) }}"
                                    data-reject-url="{{ route('knowledge-ingestions.reject', $ingestion) }}"
                                >
                                    <div class="knowledge-card-header">
                                        <h5>{{ $ingestion->original_filename ?? 'Testo incollato' }}</h5>
                                        <span class="knowledge-status">{{ str_replace('_', ' ', $ingestion->status->value) }}</span>
                                    </div>

                                    @if ($ingestion->status->value === 'awaiting_review')
                                        @forelse ($ingestion->items->groupBy('character_id') as $items)
                                            @php($target = $items->first()->character)
                                            <section class="knowledge-group">
                                                <h6>
                                                    {{ $target->is_global ? 'Sintesi globali derivate' : $target->name }}
                                                </h6>
                                                @foreach ($items as $item)
                                                    <label class="knowledge-item">
                                                        <input type="checkbox" value="{{ $item->id }}" data-knowledge-item @checked($item->selected)>
                                                        <span>
                                                            <p>{{ $item->content }}</p>
                                                            <span class="knowledge-meta">
                                                                {{ $item->action }} · {{ $item->category }} · importanza {{ $item->importance }}/5 · confidenza {{ round($item->confidence * 100) }}%
                                                                @if ($item->source_reference)
                                                                    · {{ $item->source_reference }}
                                                                @endif
                                                            </span>
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </section>
                                        @empty
                                            <p class="knowledge-empty">Nessun fatto pertinente trovato. Puoi chiudere o rifiutare l’importazione.</p>
                                        @endforelse

                                        <div class="knowledge-actions">
                                            <button type="button" class="primary" data-confirm-knowledge>Conferma selezionati</button>
                                            <button type="button" class="secondary danger" data-reject-knowledge>Rifiuta tutto</button>
                                        </div>
                                    @elseif ($ingestion->status->value === 'failed')
                                        <p class="knowledge-empty">{{ $ingestion->error_message ?? 'Elaborazione non riuscita.' }}</p>
                                        <div class="knowledge-actions">
                                            <button type="button" class="secondary danger" data-reject-knowledge>Elimina dati temporanei</button>
                                        </div>
                                    @else
                                        <p class="knowledge-empty">Elaborazione asincrona in corso…</p>
                                        <div class="knowledge-actions">
                                            <button type="button" class="secondary danger" data-reject-knowledge>Annulla</button>
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </section>

                    @unless ($selectedCharacter->is_global)
                        <section class="integration-card">
                            <h4>Elimina specialista</h4>
                            <p>Elimina definitivamente il personaggio, le sue chat e tutte le sue memorie.</p>
                            <form method="POST" action="{{ route('characters.destroy', $selectedCharacter) }}" onsubmit="return window.confirm('Eliminare definitivamente questo specialista?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="secondary danger">Elimina {{ $selectedCharacter->name }}</button>
                            </form>
                        </section>
                    @endunless

                </aside>
            </div>
        @endif

        <div class="settings-backdrop" id="account-settings-backdrop" @if (! $accountSettingsOpen) hidden @endif>
            <aside class="settings-panel" role="dialog" aria-modal="true" aria-labelledby="account-settings-title">
                <div class="settings-header">
                    <h3 id="account-settings-title">Il mio account</h3>
                    <button type="button" class="secondary" id="close-account-settings">Chiudi</button>
                </div>

                <form class="settings-form" id="account-profile-form">
                    <label>Nome
                        <input name="name" value="{{ auth()->user()->name }}" required maxlength="255" autocomplete="name">
                    </label>
                    <label>Email
                        <input type="email" name="email" value="{{ auth()->user()->email }}" required maxlength="255" autocomplete="email">
                    </label>
                    <label>Fuso orario
                        <select name="timezone" required>
                            @foreach ($timezones as $timezone)
                                <option value="{{ $timezone }}" @selected(auth()->user()->timezone === $timezone)>{{ $timezone }}</option>
                            @endforeach
                        </select>
                    </label>
                    <p class="feedback" id="account-profile-feedback"></p>
                    <button type="submit" class="primary">Salva profilo</button>
                </form>

                <section class="integration-card">
                    <h4>Cambia password</h4>
                    <form class="settings-form" id="account-password-form">
                        <label>Password attuale
                            <input type="password" name="current_password" required autocomplete="current-password">
                        </label>
                        <label>Nuova password
                            <input type="password" name="password" required autocomplete="new-password">
                        </label>
                        <label>Conferma nuova password
                            <input type="password" name="password_confirmation" required autocomplete="new-password">
                        </label>
                        <p class="feedback" id="account-password-feedback"></p>
                        <button type="submit" class="primary">Aggiorna password</button>
                    </form>
                </section>

                <section class="integration-card">
                    <h4>Integrazioni</h4>
                    <p>I collegamenti appartengono al tuo account e sono disponibili automaticamente a tutti gli specialisti quando servono.</p>
                </section>

                @foreach ($integrationProviders as $provider)
                    <section class="integration-card">
                        <h4>{{ $provider->label() }}</h4>
                        @php($userConnection = $integrationConnections->get($provider->value))
                        @php($hasLegacyGoogle = $provider->value === 'google' && $integrationConnections->keys()->intersect(['google_calendar', 'google_drive', 'google_gmail'])->isNotEmpty())
                        @if ($userConnection)
                            <p>
                                Collegato
                                @if (data_get($userConnection->metadata, 'account_name'))
                                    come {{ data_get($userConnection->metadata, 'account_name') }}
                                @endif
                                @if (data_get($userConnection->metadata, 'workspace_name'))
                                    in {{ data_get($userConnection->metadata, 'workspace_name') }}
                                @endif
                                .
                            </p>
                            @unless ($userConnection->hasRequiredScopes())
                                <p class="notice error">Mancano alcuni permessi: ricollega il servizio.</p>
                            @endunless
                            <div class="integration-actions">
                                <a class="secondary" href="{{ route('integrations.redirect', $provider) }}">Ricollega</a>
                                <form method="POST" action="{{ route('integrations.destroy', $provider) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="secondary danger">Scollega dall’account</button>
                                </form>
                            </div>
                        @elseif ($hasLegacyGoogle)
                            <p>Hai connessioni Google precedenti. Ricollega una volta per unificare Drive, Calendar e Gmail.</p>
                            <a class="primary" href="{{ route('integrations.redirect', $provider) }}">Unifica Google</a>
                        @elseif ($integrationConfigured->get($provider->value))
                            <p>Accedi con il tuo account {{ $provider->label() }} e conferma i permessi: non serve altro.</p>
                            <a class="primary" href="{{ route('integrations.redirect', $provider) }}">Collega {{ $provider->label() }}</a>
                        @else
                            <p>Questa integrazione non è ancora disponibile.</p>
                        @endif
                    </section>
                @endforeach
            </aside>
        </div>

        <script>
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const feedback = document.getElementById('feedback');
            const messages = document.getElementById('messages');
            const form = document.getElementById('chat-form');
            const input = document.getElementById('message');
            const sendButton = document.getElementById('send-button');
            const closeButton = document.getElementById('close-chat');
            const discardButton = document.getElementById('discard-chat');
            const conversationTitle = document.getElementById('conversation-title');
            const currentConversationTitleLink = document.querySelector('[data-current-conversation-title="true"]');
            const settingsBackdrop = document.getElementById('settings-backdrop');
            const settingsPanel = settingsBackdrop?.querySelector('.settings-panel');
            const settingsForm = document.getElementById('settings-form');
            const settingsFeedback = document.getElementById('settings-feedback');
            const knowledgeForm = document.getElementById('knowledge-form');
            const knowledgeFeedback = document.getElementById('knowledge-feedback');
            const knowledgeTextarea = knowledgeForm?.querySelector('textarea[name="text"]');
            const knowledgeDraftKey = {{ Js::from($selectedCharacter ? 'knowledge-draft-'.$selectedCharacter->id : null) }};
            const accountSettingsBackdrop = document.getElementById('account-settings-backdrop');
            const accountProfileForm = document.getElementById('account-profile-form');
            const accountProfileFeedback = document.getElementById('account-profile-feedback');
            const accountPasswordForm = document.getElementById('account-password-form');
            const accountPasswordFeedback = document.getElementById('account-password-feedback');
            const createCharacterForm = document.getElementById('create-character-form');
            const createCharacterFeedback = document.getElementById('create-character-feedback');
            const messageUrl = {{ Js::from($selectedConversation ? route('conversations.messages.store', $selectedConversation) : null) }};
            const closeUrl = {{ Js::from($selectedConversation ? route('conversations.closed.store', $selectedConversation) : null) }};
            const discardUrl = {{ Js::from($selectedConversation ? route('conversations.destroy', $selectedConversation) : null) }};
            const conversationStoreUrl = {{ Js::from(route('conversations.store')) }};
            const characterStoreUrl = {{ Js::from(route('characters.store')) }};
            const characterUpdateUrl = {{ Js::from($selectedCharacter ? route('characters.update', $selectedCharacter) : null) }};
            const knowledgeStoreUrl = {{ Js::from($selectedCharacter ? route('knowledge-ingestions.store', $selectedCharacter) : null) }};
            const accountProfileUrl = {{ Js::from(route('account.profile.update')) }};
            const accountPasswordUrl = {{ Js::from(route('account.password.update')) }};

            const request = async (url, body = {}, method = 'POST') => {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(body),
                });
                const rawBody = await response.text();
                let data;

                try {
                    data = JSON.parse(rawBody);
                } catch {
                    throw new Error(`Il server ha risposto con testo non JSON (status ${response.status}).`);
                }

                if (!response.ok) {
                    throw new Error(data.message || `Errore ${response.status}`);
                }

                return data;
            };

            const reloadWithCharacterSettings = () => {
                if (settingsPanel) {
                    sessionStorage.setItem('settings-panel-scroll', String(settingsPanel.scrollTop));
                }

                const url = new URL(window.location.href);
                url.searchParams.set('settings', '1');
                window.location.href = url.toString();
            };

            let knowledgeReloadPending = false;

            const hasKnowledgeDraft = () => Boolean(knowledgeTextarea?.value.trim());

            const isKnowledgeEditing = () => (
                document.activeElement === knowledgeTextarea || hasKnowledgeDraft()
            );

            const saveKnowledgeDraft = () => {
                if (!knowledgeTextarea || !knowledgeDraftKey) return;

                if (hasKnowledgeDraft()) {
                    sessionStorage.setItem(knowledgeDraftKey, knowledgeTextarea.value);
                } else {
                    sessionStorage.removeItem(knowledgeDraftKey);
                }
            };

            const restoreKnowledgeDraft = () => {
                if (!knowledgeTextarea || !knowledgeDraftKey) return;

                const draft = sessionStorage.getItem(knowledgeDraftKey);

                if (draft) {
                    knowledgeTextarea.value = draft;
                    resizeKnowledgeTextarea();
                }
            };

            const clearKnowledgeDraft = () => {
                if (knowledgeDraftKey) {
                    sessionStorage.removeItem(knowledgeDraftKey);
                }
            };

            const resizeKnowledgeTextarea = () => {
                if (!knowledgeTextarea) return;

                knowledgeTextarea.style.height = 'auto';
                knowledgeTextarea.style.height = `${Math.min(knowledgeTextarea.scrollHeight, 18 * 16)}px`;
            };

            const queueKnowledgeReload = () => {
                if (isKnowledgeEditing()) {
                    knowledgeReloadPending = true;
                    return;
                }

                reloadWithCharacterSettings();
            };

            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebar-backdrop');
            const sidebarToggle = document.getElementById('sidebar-toggle');
            const menus = Array.from(document.querySelectorAll('[data-menu]'));

            const setSidebar = (open) => {
                if (!sidebar) return;
                sidebar.classList.toggle('open', open);
                if (sidebarBackdrop) sidebarBackdrop.hidden = !open;
                sidebarToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
            };

            const closeMenus = (except = null) => {
                menus.forEach((menu) => {
                    if (menu === except) return;
                    menu.querySelector('[data-menu-panel]').hidden = true;
                    menu.querySelector('[data-menu-trigger]').setAttribute('aria-expanded', 'false');
                });
            };

            sidebarToggle?.addEventListener('click', () => setSidebar(!sidebar.classList.contains('open')));
            sidebarBackdrop?.addEventListener('click', () => setSidebar(false));

            menus.forEach((menu) => {
                const trigger = menu.querySelector('[data-menu-trigger]');
                const panel = menu.querySelector('[data-menu-panel]');

                trigger.addEventListener('click', () => {
                    const shouldOpen = panel.hidden;
                    closeMenus(menu);
                    panel.hidden = !shouldOpen;
                    trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
                });

                panel.addEventListener('click', (event) => {
                    if (event.target.closest('[data-keep-open]')) return;
                    closeMenus();
                });
            });

            const themeToggle = document.getElementById('theme-toggle');

            const applyTheme = (theme) => {
                document.documentElement.dataset.theme = theme;
                themeToggle?.setAttribute('aria-checked', theme === 'dark' ? 'true' : 'false');
            };

            applyTheme(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light');

            themeToggle?.addEventListener('click', () => {
                const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';

                try {
                    window.localStorage.setItem('theme', theme);
                } catch (error) {
                    // Senza storage il tema resta valido solo per questa pagina.
                }

                applyTheme(theme);
            });

            document.addEventListener('click', (event) => {
                if (!event.target.closest('[data-menu]')) closeMenus();
            });

            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                closeMenus();
                setSidebar(false);
            });

            const setFeedback = (text, isError = false) => {
                if (!feedback) return;
                feedback.textContent = text;
                feedback.classList.toggle('error', isError);
            };

            const bindProposal = (card) => {
                const confirmButton = card.querySelector('[data-confirm-proposal]');
                const rejectButton = card.querySelector('[data-reject-proposal]');

                confirmButton?.addEventListener('click', async () => {
                    confirmButton.disabled = true;
                    if (rejectButton) rejectButton.disabled = true;
                    try {
                        const data = await request(card.dataset.confirmUrl);
                        card.querySelector('.proposal-status').textContent = data.status;
                        card.querySelector('.proposal-actions')?.remove();
                        const externalLink = data.result?.html_link || data.result?.web_link;
                        if (externalLink) {
                            const link = document.createElement('a');
                            link.href = externalLink;
                            link.target = '_blank';
                            link.rel = 'noopener';
                            link.textContent = 'Apri il risultato';
                            const row = document.createElement('p');
                            row.appendChild(link);
                            card.appendChild(row);
                        }
                        setFeedback(data.message);
                    } catch (error) {
                        setFeedback(error.message, true);
                        confirmButton.disabled = false;
                        if (rejectButton) rejectButton.disabled = false;
                    }
                });

                rejectButton?.addEventListener('click', async () => {
                    confirmButton.disabled = true;
                    rejectButton.disabled = true;
                    try {
                        const data = await request(card.dataset.rejectUrl);
                        card.querySelector('.proposal-status').textContent = data.status;
                        card.querySelector('.proposal-actions')?.remove();
                        setFeedback(data.message);
                    } catch (error) {
                        setFeedback(error.message, true);
                        confirmButton.disabled = false;
                        rejectButton.disabled = false;
                    }
                });
            };

            const renderProposal = (proposal) => {
                const card = document.createElement('section');
                card.className = 'proposal';
                card.dataset.proposal = '';
                card.dataset.confirmUrl = proposal.confirm_url;
                card.dataset.rejectUrl = proposal.reject_url;

                const status = document.createElement('span');
                status.className = 'proposal-status';
                status.textContent = proposal.status;
                const title = document.createElement('h4');
                title.textContent = proposal.title || `Azione ${proposal.provider_label || 'esterna'}`;
                card.append(status, title);

                if (proposal.type.includes('calendar.') || proposal.type.includes('create_event')) {
                    const timing = document.createElement('p');
                    timing.textContent = `${proposal.payload.start} → ${proposal.payload.end}`;
                    card.appendChild(timing);
                }
                if (proposal.payload.location) {
                    const location = document.createElement('p');
                    location.textContent = `Luogo: ${proposal.payload.location}`;
                    card.appendChild(location);
                }
                if (proposal.type.includes('mail.')) {
                    const recipients = document.createElement('p');
                    recipients.textContent = `A: ${proposal.payload.to.join(', ')}`;
                    const body = document.createElement('p');
                    body.textContent = proposal.payload.body;
                    card.append(recipients, body);
                } else if (proposal.payload.content || proposal.payload.text || proposal.payload.body) {
                    const content = document.createElement('p');
                    content.textContent = proposal.payload.content || proposal.payload.text || proposal.payload.body;
                    card.appendChild(content);
                }

                const actions = document.createElement('div');
                actions.className = 'proposal-actions';
                actions.innerHTML = '<button type="button" class="primary" data-confirm-proposal>Conferma</button><button type="button" class="secondary danger" data-reject-proposal>Rifiuta</button>';
                card.appendChild(actions);
                bindProposal(card);

                return card;
            };

            document.querySelectorAll('[data-proposal]').forEach(bindProposal);

            document.querySelectorAll('[data-new-character]').forEach((button) => {
                button.addEventListener('click', async () => {
                    button.disabled = true;
                    try {
                        const data = await request(conversationStoreUrl, {
                            character_id: Number(button.dataset.newCharacter),
                        });
                        window.location.href = data.url;
                    } catch (error) {
                        setFeedback(error.message, true);
                        button.disabled = false;
                    }
                });
            });

            if (form) {
                const resizeInput = () => {
                    input.style.height = 'auto';
                    input.style.height = `${input.scrollHeight}px`;
                };

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const content = input.value.trim();
                    if (!content) return;

                    document.getElementById('empty-state')?.remove();
                    const userMessage = document.createElement('article');
                    userMessage.className = 'message user';
                    userMessage.textContent = content;
                    messages.appendChild(userMessage);
                    input.value = '';
                    resizeInput();
                    input.disabled = true;
                    sendButton.disabled = true;
                    sendButton.textContent = 'Attendi…';
                    setFeedback('');

                    try {
                        const data = await request(messageUrl, { message: content });
                        if (data.conversation_title) {
                            conversationTitle.textContent = data.conversation_title;

                            if (currentConversationTitleLink) {
                                currentConversationTitleLink.textContent = `● ${data.conversation_title}`;
                                currentConversationTitleLink.title = data.conversation_title;
                            }
                        }
                        const assistantMessage = document.createElement('article');

                        if (data.reply_html) {
                            assistantMessage.className = 'message assistant rich-text';
                            assistantMessage.innerHTML = data.reply_html;
                        } else {
                            assistantMessage.className = 'message assistant';
                            assistantMessage.textContent = data.reply;
                        }

                        messages.appendChild(assistantMessage);
                        (data.proposals || (data.proposal ? [data.proposal] : []))
                            .forEach((proposal) => messages.appendChild(renderProposal(proposal)));
                        const integrationError = Object.values(data.integration_errors || {})[0];
                        setFeedback(integrationError || '', Boolean(integrationError));
                        messages.scrollTop = messages.scrollHeight;
                    } catch (error) {
                        setFeedback(error.message, true);
                    } finally {
                        input.disabled = false;
                        sendButton.disabled = false;
                        sendButton.textContent = 'Invia';
                        input.focus();
                    }
                });

                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        form.requestSubmit();
                    }
                });

                input.addEventListener('input', resizeInput);
                resizeInput();

                messages.scrollTop = messages.scrollHeight;
                input.focus();
            }

            createCharacterForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = createCharacterForm.querySelector('button[type="submit"]');
                button.disabled = true;
                createCharacterFeedback.textContent = '';
                createCharacterFeedback.classList.remove('error');

                try {
                    const values = Object.fromEntries(new FormData(createCharacterForm));
                    const data = await request(characterStoreUrl, values);
                    createCharacterFeedback.textContent = data.message;
                    window.location.href = data.url;
                } catch (error) {
                    createCharacterFeedback.textContent = error.message;
                    createCharacterFeedback.classList.add('error');
                    button.disabled = false;
                }
            });

            document.getElementById('open-settings')?.addEventListener('click', () => {
                settingsBackdrop.hidden = false;
            });
            document.getElementById('close-settings')?.addEventListener('click', () => {
                settingsBackdrop.hidden = true;
            });
            settingsBackdrop?.addEventListener('click', (event) => {
                if (event.target === settingsBackdrop) settingsBackdrop.hidden = true;
            });
            settingsForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = document.getElementById('save-settings');
                button.disabled = true;
                settingsFeedback.textContent = '';
                settingsFeedback.classList.remove('error');

                try {
                    const values = Object.fromEntries(new FormData(settingsForm));
                    const data = await request(characterUpdateUrl, values, 'PATCH');
                    settingsFeedback.textContent = data.message;
                    window.setTimeout(() => window.location.reload(), 350);
                } catch (error) {
                    settingsFeedback.textContent = error.message;
                    settingsFeedback.classList.add('error');
                    button.disabled = false;
                }
            });

            const bindKnowledgeCard = (card) => {
                const confirmButton = card.querySelector('[data-confirm-knowledge]');
                const rejectButton = card.querySelector('[data-reject-knowledge]');

                confirmButton?.addEventListener('click', async () => {
                    confirmButton.disabled = true;
                    if (rejectButton) rejectButton.disabled = true;

                    try {
                        const selectedItems = Array.from(card.querySelectorAll('[data-knowledge-item]:checked'))
                            .map((checkbox) => Number(checkbox.value));
                        const data = await request(card.dataset.confirmUrl, {
                            selected_items: selectedItems,
                        });
                        knowledgeFeedback.textContent = data.message;
                        knowledgeFeedback.classList.remove('error');
                        card.remove();
                    } catch (error) {
                        knowledgeFeedback.textContent = error.message;
                        knowledgeFeedback.classList.add('error');
                        confirmButton.disabled = false;
                        if (rejectButton) rejectButton.disabled = false;
                    }
                });

                rejectButton?.addEventListener('click', async () => {
                    if (!window.confirm('Rifiutare l’importazione ed eliminare tutti i dati temporanei?')) return;
                    rejectButton.disabled = true;
                    if (confirmButton) confirmButton.disabled = true;

                    try {
                        const data = await request(card.dataset.rejectUrl);
                        knowledgeFeedback.textContent = data.message;
                        knowledgeFeedback.classList.remove('error');
                        card.remove();
                    } catch (error) {
                        knowledgeFeedback.textContent = error.message;
                        knowledgeFeedback.classList.add('error');
                        rejectButton.disabled = false;
                        if (confirmButton) confirmButton.disabled = false;
                    }
                });
            };

            document.querySelectorAll('[data-knowledge-ingestion]').forEach(bindKnowledgeCard);

            restoreKnowledgeDraft();

            if (settingsPanel) {
                const savedScroll = sessionStorage.getItem('settings-panel-scroll');

                if (savedScroll !== null) {
                    settingsPanel.scrollTop = Number(savedScroll);
                    sessionStorage.removeItem('settings-panel-scroll');
                }
            }

            knowledgeTextarea?.addEventListener('input', () => {
                saveKnowledgeDraft();
                resizeKnowledgeTextarea();
            });

            knowledgeTextarea?.addEventListener('paste', () => {
                window.requestAnimationFrame(() => {
                    saveKnowledgeDraft();
                    resizeKnowledgeTextarea();
                });
            });

            knowledgeTextarea?.addEventListener('blur', () => {
                saveKnowledgeDraft();

                if (knowledgeReloadPending && !isKnowledgeEditing()) {
                    knowledgeReloadPending = false;
                    reloadWithCharacterSettings();
                }
            });

            knowledgeForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = knowledgeForm.querySelector('button[type="submit"]');
                const payload = new FormData(knowledgeForm);
                button.disabled = true;
                knowledgeFeedback.textContent = '';
                knowledgeFeedback.classList.remove('error');

                try {
                    const response = await fetch(knowledgeStoreUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: payload,
                    });
                    const data = await response.json();

                    if (!response.ok) {
                        throw new Error(data.message || `Errore ${response.status}`);
                    }

                    knowledgeFeedback.textContent = data.message;
                    clearKnowledgeDraft();
                    knowledgeForm.reset();
                    resizeKnowledgeTextarea();
                    window.setTimeout(reloadWithCharacterSettings, 250);
                } catch (error) {
                    knowledgeFeedback.textContent = error.message;
                    knowledgeFeedback.classList.add('error');
                    button.disabled = false;
                }
            });

            const processingKnowledgeCards = Array.from(
                document.querySelectorAll('[data-knowledge-ingestion][data-status="pending"], [data-knowledge-ingestion][data-status="processing"]'),
            );

            if (processingKnowledgeCards.length > 0) {
                window.setInterval(async () => {
                    for (const card of processingKnowledgeCards) {
                        try {
                            const response = await fetch(card.dataset.statusUrl, {
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });

                            if (!response.ok) continue;
                            const data = await response.json();

                            if (
                                data.ingestion.status !== card.dataset.status
                                || Number(data.ingestion.item_count) !== Number(card.dataset.itemCount)
                            ) {
                                queueKnowledgeReload();
                                return;
                            }
                        } catch {
                            // Il polling riprova al ciclo successivo.
                        }
                    }
                }, 2000);
            }

            document.getElementById('open-account-settings')?.addEventListener('click', () => {
                accountSettingsBackdrop.hidden = false;
            });
            document.getElementById('close-account-settings')?.addEventListener('click', () => {
                accountSettingsBackdrop.hidden = true;
            });
            accountSettingsBackdrop?.addEventListener('click', (event) => {
                if (event.target === accountSettingsBackdrop) accountSettingsBackdrop.hidden = true;
            });
            accountProfileForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = accountProfileForm.querySelector('button[type="submit"]');
                button.disabled = true;
                accountProfileFeedback.textContent = '';
                accountProfileFeedback.classList.remove('error');

                try {
                    const values = Object.fromEntries(new FormData(accountProfileForm));
                    const data = await request(accountProfileUrl, values, 'PATCH');
                    accountProfileFeedback.textContent = data.message;
                    window.setTimeout(() => window.location.reload(), 350);
                } catch (error) {
                    accountProfileFeedback.textContent = error.message;
                    accountProfileFeedback.classList.add('error');
                    button.disabled = false;
                }
            });
            accountPasswordForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = accountPasswordForm.querySelector('button[type="submit"]');
                button.disabled = true;
                accountPasswordFeedback.textContent = '';
                accountPasswordFeedback.classList.remove('error');

                try {
                    const values = Object.fromEntries(new FormData(accountPasswordForm));
                    const data = await request(accountPasswordUrl, values, 'PATCH');
                    accountPasswordFeedback.textContent = data.message;
                    accountPasswordForm.reset();
                } catch (error) {
                    accountPasswordFeedback.textContent = error.message;
                    accountPasswordFeedback.classList.add('error');
                } finally {
                    button.disabled = false;
                }
            });

            if (closeButton) {
                closeButton.addEventListener('click', async () => {
                    if (!window.confirm('Chiudere la chat e consolidare le informazioni importanti nella memoria?')) return;
                    closeButton.disabled = true;
                    closeButton.textContent = 'Salvataggio…';
                    setFeedback('');

                    try {
                        const data = await request(closeUrl);
                        setFeedback(data.message);
                        window.location.href = data.url;
                    } catch (error) {
                        setFeedback(error.message, true);
                        closeButton.disabled = false;
                        closeButton.textContent = 'Chiudi e salva memoria';
                    }
                });
            }

            if (discardButton) {
                discardButton.addEventListener('click', async () => {
                    const confirmation = discardButton.dataset.discardActive === '1'
                        ? 'Eliminare questa chat senza salvare alcuna memoria? Messaggi e fatti emersi qui verranno cancellati.'
                        : 'Eliminare questa chat dalla lista? Il riepilogo scomparirà, le memorie già salvate restano.';

                    if (!window.confirm(confirmation)) {
                        return;
                    }

                    const originalLabel = discardButton.textContent;
                    discardButton.disabled = true;
                    discardButton.textContent = 'Eliminazione…';
                    setFeedback('');

                    try {
                        const data = await request(discardUrl, {}, 'DELETE');
                        setFeedback(data.message);
                        window.location.href = data.url;
                    } catch (error) {
                        setFeedback(error.message, true);
                        discardButton.disabled = false;
                        discardButton.textContent = originalLabel;
                    }
                });
            }
        </script>
    </body>
</html>
