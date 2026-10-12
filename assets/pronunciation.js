(() => {
    const verses = document.querySelectorAll('.verse-text');
    const toggle = document.getElementById('pronunciationToggle');
    const CACHE_KEY = 'biblePronunciationCacheV2';
    const PREF_KEY = 'bibleShowPhonetics';
    let cache = {};
    try { cache = JSON.parse(localStorage.getItem(CACHE_KEY) || '{}') || {}; } catch (_) {}
    function saveCache() { try { localStorage.setItem(CACHE_KEY, JSON.stringify(cache)); } catch (_) {} }
    function normalizeWord(word) { return word.toLowerCase().replace(/[’‘]/g, "'"); }
    function tokenize(text) { return text.split(/(\s+|[—–-]|[.,!?;:"“”‘’()]+)/).filter(part => part !== ''); }
    function isWord(part) { return /[\p{L}]/u.test(part); }
    const pending = new Map();
    function wrapVerse(span) {
        if (span.dataset.phoneticsWrapped === '1') return;
        const parts = tokenize(span.textContent); span.textContent = '';
        parts.forEach(part => {
            if (!isWord(part)) { span.appendChild(document.createTextNode(part)); return; }
            const match = part.match(/^([^\p{L}]*)([\p{L}][\p{L}'’\-]*)([^\p{L}]*)$/u);
            if (!match) { span.appendChild(document.createTextNode(part)); return; }
            const [, prefix, word, suffix] = match;
            if (prefix) span.appendChild(document.createTextNode(prefix));
            const unit = document.createElement('span'); unit.className = 'word-unit';
            const written = document.createElement('span'); written.className = 'word-original'; written.textContent = word;
            const ipa = document.createElement('span'); ipa.className = 'word-ipa pending'; ipa.textContent = '…';
            unit.append(written, ipa); span.appendChild(unit);
            if (suffix) span.appendChild(document.createTextNode(suffix));
            const key = normalizeWord(word);
            if (Object.prototype.hasOwnProperty.call(cache, key)) showResult(cache[key], ipa);
            else { if (!pending.has(key)) pending.set(key, []); pending.get(key).push(ipa); }
        });
        span.dataset.phoneticsWrapped = '1';
    }
    function showResult(result, element) {
        element.classList.remove('pending');
        if (result) { element.textContent = result; element.setAttribute('aria-label', `Pronúncia: ${result}`); }
        else { element.textContent = ''; element.classList.add('unavailable'); element.setAttribute('aria-label', 'Transcrição fonética não disponível'); }
    }
    async function loadFromDatabase() {
        const words = [...pending.keys()];
        if (!words.length) return;
        // Pedidos em blocos evitam URLs enormes e carga desnecessária no servidor.
        for (let i = 0; i < words.length; i += 250) {
            const batch = words.slice(i, i + 250);
            try {
                const response = await fetch('api/transcriptions.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ words: batch }) });
                if (!response.ok) throw new Error('Falha na consulta local');
                const data = await response.json();
                const results = data.transcriptions || {};
                batch.forEach(word => {
                    const value = Object.prototype.hasOwnProperty.call(results, word) ? results[word] : null;
                    cache[word] = value === '---' || value === '-' || value === '' ? null : value;
                    (pending.get(word) || []).forEach(element => showResult(cache[word], element));
                    pending.delete(word);
                });
                saveCache();
            } catch (_) {
                batch.forEach(word => { cache[word] = null; (pending.get(word) || []).forEach(element => showResult(null, element)); pending.delete(word); });
                saveCache();
            }
        }
    }
    if (localStorage.getItem(PREF_KEY) === 'false') document.body.classList.add('hide-phonetics');
    function updateToggle() {
        const hidden = document.body.classList.contains('hide-phonetics');
        toggle.setAttribute('aria-pressed', String(!hidden));
        toggle.innerHTML = `Aa <span>${hidden ? 'Mostrar fonética' : 'Ocultar fonética'}</span>`;
    }
    toggle.addEventListener('click', () => {
        document.body.classList.toggle('hide-phonetics');
        localStorage.setItem(PREF_KEY, String(!document.body.classList.contains('hide-phonetics'))); updateToggle();
    });
    verses.forEach(wrapVerse); updateToggle(); loadFromDatabase();
})();
