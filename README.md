# Leitor Bíblico PHP + MySQL — progresso, contas e histórico

## Requisitos
- PHP 8.1+ com `pdo_mysql` e `mbstring` habilitados.
- MySQL/MariaDB (Laragon funciona bem).
- JSON da Bíblia: `Books/Books.json` e os ficheiros de livros em `Books/contentBook/` (o migrador também procura ficheiros JSON na raiz).
- Dump SQL completo do dicionário no caminho indicado por `TRANSCRIPTION_SQL_FILE`.

## Instalação
1. Copie os ficheiros do projecto para `C:\laragon\www\Bible-niv`.
2. Edite `.env` com as credenciais MySQL. O caminho padrão do dicionário é `schema/transcription_table.sql`.
3. Crie no phpMyAdmin a base de dados definida por `DB_DATABASE` (por exemplo, `bible_reader`).
4. Coloque `Books.json` em `Books/Books.json` e os JSON dos livros em `Books/contentBook/`.
5. Coloque o dump completo do dicionário em `schema/transcription_table.sql` (ou altere `TRANSCRIPTION_SQL_FILE`).
6. Execute no terminal do Laragon, dentro da pasta do projecto: `php migrate.php`.
7. Abra `http://localhost/Bible-niv/`.

## Contas e progresso
- `auth.php?mode=register`: cria conta; `auth.php?mode=login`: inicia sessão.
- Visitantes guardam progresso e preferências em `localStorage`, apenas naquele navegador.
- Utilizadores autenticados guardam capítulos lidos, versículos marcados, ciclos de leitura e datas de conclusão no MySQL.
- Ao marcar todos os capítulos existentes como lidos no ciclo actual, o sistema regista a conclusão e inicia automaticamente o próximo ciclo sem apagar o histórico anterior.
- O número de capítulos é calculado a partir dos capítulos existentes em `bible_verses`, não depende de um valor fixo no JavaScript.
- A palavra-passe é guardada com `password_hash`, nunca em texto simples.

## Tabelas principais
- `bible_books`: livros e ordem canónica.
- `bible_verses`: capítulos e versículos.
- `transcription_table`: dicionário fonético local; `---` significa sem transcrição.
- `users`: contas.
- `reading_cycles`: um registo por ciclo de leitura, com data de início e conclusão.
- `reading_chapters`: capítulos marcados no ciclo activo.
- `user_verse_marks`: versículos marcados por utilizador.

## Segurança e notas
- Não publiques o `.env`; está incluído no `.gitignore`.
- O ficheiro `migrate.php` só pode ser executado pelo terminal.
- Executar `migrate.php` volta a importar os versículos. Não apaga as contas nem os ciclos de leitura.
- A importação do dicionário usa `INSERT IGNORE` para evitar duplicar palavras se o dump for executado novamente.
- Depois de confirmar a migração, mantém uma cópia de segurança dos JSON originais.
