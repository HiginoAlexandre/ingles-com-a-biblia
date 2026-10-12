(() => {
    const $ = (selector) => document.querySelector(selector);
    const config = window.BIBLE_READER || { book: 'Genesis', chapter: 1, totalChapters: 1189, loggedIn: false };
    const toast = $('#toast');
    let toastTimer;
    let busy = false;
    let serverHistory = [];
    function notify(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 2800);
    }
    const storage = {
        get(key, fallback) {
            try { const value = localStorage.getItem(key); return value === null ? fallback : JSON.parse(value); }
            catch (_) { return fallback; }
        },
        set(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch (_) {} }
    };
    const chapterKey = `${config.book} ${config.chapter}`;
    let markedChapters = storage.get('bibleMarkedChapters', []);
    let markedVerses = storage.get('bibleMarkedVerses', {});
    let completedReadings = Number(storage.get('bibleCompletedReadings', 0)) || 0;
    let currentCycle = 1;
    const allChapters = Number(config.totalChapters) || 1189;

    function renderProgress() {
        markedChapters = Array.isArray(markedChapters) ? [...new Set(markedChapters)] : [];
        const count = Math.min(markedChapters.length, allChapters);
        $('#progressCount').textContent = count;
        const percent = allChapters ? Math.min(100, Math.round(count / allChapters * 100)) : 0;
        $('#progressBar').style.width = `${percent}%`;
        $('#progressPercent').textContent = `${percent}% da Bíblia marcada como lida`;
        $('#completedReadings').textContent = completedReadings;
        $('#cycleLabel').textContent = config.loggedIn ? `Ciclo de leitura actual: ${currentCycle}` : 'Leitura de visitante guardada neste navegador';
        const isMarked = markedChapters.includes(chapterKey);
        $('#markChapter').innerHTML = `${isMarked ? '✓' : '♧'} <span>${isMarked ? 'Capítulo marcado como lido' : 'Marcar capítulo como lido'}</span>`;
    }
    function renderVerseMarks() {
        document.querySelectorAll('.verse').forEach(verse => {
            const number = verse.dataset.verse;
            const key = `${chapterKey}:${number}`;
            verse.classList.toggle('selected', Boolean(markedVerses[key]));
        });
    }
    function renderBookmarks() {
        const panel = $('#bookmarksPanel');
        panel.innerHTML = '';
        if (!markedChapters.length) {
            const p = document.createElement('p'); p.className = 'progress-note'; p.textContent = 'Ainda não marcou capítulos como lidos.'; panel.appendChild(p); return;
        }
        [...markedChapters].reverse().forEach(reference => {
            const a = document.createElement('a'); a.className = 'bookmark-link';
            const split = reference.lastIndexOf(' ');
            const book = reference.slice(0, split); const chapter = reference.slice(split + 1);
            a.href = `?book=${encodeURIComponent(book)}&chapter=${encodeURIComponent(chapter)}`;
            a.textContent = `✓ ${reference}`; panel.appendChild(a);
        });
    }
    function renderHistory() {
        const panel = $('#historyPanel'); panel.innerHTML = '';
        const history = config.loggedIn ? serverHistory : storage.get('bibleReadingHistory', []);
        if (!history.length) {
            const p = document.createElement('p'); p.className = 'progress-note'; p.textContent = 'Ainda não concluiu uma leitura completa da Bíblia.'; panel.appendChild(p); return;
        }
        history.forEach(item => {
            const p = document.createElement('p'); p.className = 'history-item';
            const date = item.completed_at || item.completedAt;
            p.textContent = `Leitura ${item.cycle_number || item.cycleNumber}: ${date ? `concluída em ${date}` : 'em curso'}`;
            panel.appendChild(p);
        });
    }
    function applyServerState(state) {
        markedChapters = Array.isArray(state.markedChapters) ? state.markedChapters : [];
        markedVerses = state.markedVerses || {};
        completedReadings = Number(state.completedReadings || 0);
        currentCycle = Number(state.currentCycle || 1);
        serverHistory = Array.isArray(state.history) ? state.history : [];
        renderProgress(); renderVerseMarks(); renderBookmarks(); renderHistory();
    }
    async function serverAction(payload) {
        const response = await fetch('api/reading.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ ...payload, csrfToken: config.csrfToken })
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível guardar os dados.');
        applyServerState(result.state);
        return result;
    }
    async function loadServerState() {
        try {
            const response = await fetch('api/reading.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Erro ao carregar progresso.');
            applyServerState(result.state);
        } catch (_) { notify('Não foi possível carregar o progresso da conta. Verifica a ligação ao servidor.'); }
    }

    renderProgress(); renderVerseMarks();
    if (config.loggedIn) loadServerState();

    $('#bookSelect').addEventListener('change', () => {
        const book = $('#bookSelect').value; window.location.href = `?book=${encodeURIComponent(book)}&chapter=1`;
    });
    $('#fontUp').addEventListener('click', () => {
        const verses = $('.verses'); if (!verses) return;
        const current = parseFloat(getComputedStyle(verses).fontSize) || 19;
        const next = Math.min(32, current + 2); verses.style.fontSize = `${next}px`; storage.set('bibleFontSize', next);
    });
    $('#fontDown').addEventListener('click', () => {
        const verses = $('.verses'); if (!verses) return;
        const current = parseFloat(getComputedStyle(verses).fontSize) || 19;
        const next = Math.max(14, current - 2); verses.style.fontSize = `${next}px`; storage.set('bibleFontSize', next);
    });
    const savedFont = storage.get('bibleFontSize', null);
    if (savedFont && $('.verses')) $('.verses').style.fontSize = `${savedFont}px`;
    const savedTheme = storage.get('bibleDarkTheme', false);
    if (savedTheme) document.body.classList.add('dark');
    $('#themeToggle').addEventListener('click', () => {
        document.body.classList.toggle('dark'); storage.set('bibleDarkTheme', document.body.classList.contains('dark'));
    });
    $('#focusToggle').addEventListener('click', () => {
        document.body.classList.toggle('focus-mode'); const active = document.body.classList.contains('focus-mode');
        $('#focusToggle').innerHTML = `${active ? '⊙' : '◎'} <span>${active ? 'Sair do foco' : 'Modo de foco'}</span>`;
    });
    $('#markChapter').addEventListener('click', async () => {
        if (busy) return; busy = true; $('#markChapter').disabled = true;
        try {
            const wasMarked = markedChapters.includes(chapterKey);
            if (config.loggedIn) {
                const result = await serverAction({ action: 'chapter', book: config.book, chapter: Number(config.chapter), read: !wasMarked });
                if (result.completedNow) notify('Parabéns! Concluíste a leitura de toda a Bíblia. O teu novo ciclo começou.');
                else notify(wasMarked ? 'Capítulo removido do ciclo actual.' : 'Capítulo guardado na tua conta.');
            } else {
                if (wasMarked) {
                    markedChapters = markedChapters.filter(item => item !== chapterKey);
                    notify('Capítulo removido dos marcadores.');
                } else {
                    markedChapters.push(chapterKey);
                    if (allChapters > 0 && new Set(markedChapters).size >= allChapters) {
                        completedReadings++;
                        const history = storage.get('bibleReadingHistory', []);
                        history.unshift({ cycleNumber: completedReadings, completedAt: new Date().toLocaleString('pt-PT') });
                        storage.set('bibleReadingHistory', history);
                        markedChapters = [];
                        storage.set('bibleCompletedReadings', completedReadings);
                        notify('Parabéns! Concluíste a leitura de toda a Bíblia. Um novo ciclo começou.');
                    } else notify('Capítulo marcado como lido.');
                }
                storage.set('bibleMarkedChapters', markedChapters); renderProgress(); renderBookmarks(); renderHistory();
            }
        } catch (error) { notify(error.message || 'Erro ao guardar o progresso.'); }
        finally { busy = false; $('#markChapter').disabled = false; }
    });
    $('#copyReference').addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(`${config.book} ${config.chapter}`); notify('Referência copiada.'); }
        catch (_) { notify(`Referência: ${config.book} ${config.chapter}`); }
    });
    document.querySelectorAll('.verse').forEach(verse => {
        const number = Number(verse.dataset.verse);
        verse.querySelector('.verse-number').addEventListener('click', async () => {
            const key = `${chapterKey}:${number}`; const nextMarked = !markedVerses[key];
            if (config.loggedIn) {
                try { await serverAction({ action: 'verse', book: config.book, chapter: Number(config.chapter), verse: number, marked: nextMarked }); notify(nextMarked ? 'Versículo guardado na tua conta.' : 'Versículo removido dos marcadores.'); }
                catch (error) { notify(error.message || 'Não foi possível guardar o versículo.'); }
            } else {
                if (nextMarked) { markedVerses[key] = true; notify(`Versículo ${number} marcado.`); }
                else { delete markedVerses[key]; notify(`Versículo ${number} desmarcado.`); }
                storage.set('bibleMarkedVerses', markedVerses); renderVerseMarks();
            }
        });
    });
    $('#showBookmarks').addEventListener('click', () => {
        const panel = $('#bookmarksPanel'); panel.hidden = !panel.hidden;
        if (!panel.hidden) renderBookmarks();
        $('#showBookmarks').innerHTML = `${panel.hidden ? 'Ver capítulos marcados' : 'Ocultar capítulos marcados'} <span>→</span>`;
    });
    $('#showHistory').addEventListener('click', () => {
        const panel = $('#historyPanel'); panel.hidden = !panel.hidden;
        if (!panel.hidden) renderHistory();
        $('#showHistory').innerHTML = `${panel.hidden ? 'Histórico de leituras' : 'Ocultar histórico'} <span>→</span>`;
    });
})();
