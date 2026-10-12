# 📖 Leitor Bíblico

Aplicação web para leitura da Bíblia, desenvolvida com PHP, MySQL, HTML, CSS e JavaScript.

O sistema permite consultar livros, capítulos e versículos, acompanhar o progresso de leitura, guardar transcrições fonéticas em inglês e manter o histórico de leituras completas dos utilizadores registados.

## ✨ Funcionalidades

- Leitura dos livros, capítulos e versículos da Bíblia.
- Consulta de transcrições fonéticas através de um dicionário MySQL.
- Registo de utilizadores e início de sessão.
- Acompanhamento do progresso de leitura.
- Marcação de capítulos e versículos.
- Histórico de ciclos de leitura concluídos.
- Armazenamento local das preferências dos visitantes.
- Configuração da aplicação através do ficheiro `.env`.

## 🛠️ Tecnologias

- PHP
- MySQL
- HTML5
- CSS3
- JavaScript
- Laragon
- PDO para acesso à base de dados

## 📋 Requisitos

- PHP com as extensões PDO e PDO MySQL.
- MySQL ou MariaDB.
- Laragon, XAMPP ou um servidor web compatível.
- Navegador moderno.

## ⚙️ Instalação

### 1. Clonar ou copiar o projecto

Coloque a pasta do projecto no directório web do Laragon:

`C:\laragon\www\Bible-niv`

### 2. Criar a base de dados

No phpMyAdmin, crie uma base de dados chamada `bible_reader`, ou utilize outro nome e actualize a configuração.

### 3. Configurar o ambiente

Edite o ficheiro `.env` na raiz do projecto:

```dotenv
APP_NAME="Leitor Biblico"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/Bible-niv
APP_TIMEZONE=Africa/Luanda

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bible_reader
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4

TRANSCRIPTION_SQL_FILE=schema/transcription_table.sql
```

Ajuste as credenciais conforme a configuração do seu MySQL.

### 4. Importar os dados

Confirme que os ficheiros JSON da Bíblia e o ficheiro SQL do dicionário estão nas localizações esperadas pelo script de migração.

Execute no terminal:

```bash
php migrate.php
```

### 5. Abrir a aplicação

Aceda a:

http://localhost/Bible-niv/

## 👤 Modos de utilização

### Visitante

O visitante pode ler a Bíblia sem criar uma conta. As informações locais de leitura podem ser guardadas no navegador.

### Utilizador registado

O utilizador pode iniciar sessão para guardar e recuperar o progresso e o histórico de leitura na base de dados.

## 🔐 Segurança

- Não publique o ficheiro `.env` nem credenciais da base de dados.
- Utilize `password_hash()` e `password_verify()` para as palavras-passe.
- Utilize consultas preparadas PDO.
- Proteja as sessões e os endpoints de leitura.
- Desactive a depuração em produção.
- Faça cópias de segurança regulares da base de dados.

## 📁 Organização

- `api/` — endpoints da aplicação.
- `assets/` — CSS e JavaScript.
- `Books/` — lista e ficheiros JSON dos livros.
- `schema/` — estrutura SQL e dados do dicionário.
- `config.php` — configuração e ligação à base de dados.
- `migrate.php` — importação e preparação da base de dados.

## 🚀 Objectivo

Oferecer uma experiência simples de leitura bíblica, com acompanhamento do progresso e histórico das leituras concluídas.

## 📄 Licença

Defina aqui a licença de distribuição do projecto, caso pretenda publicá-lo.