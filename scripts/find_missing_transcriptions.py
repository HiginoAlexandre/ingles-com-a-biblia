from pathlib import Path

script = r'''#!/usr/bin/env python3
"""
Audita os JSON da Bíblia e identifica palavras sem transcrição fonética no MySQL.

Uso:
    python scripts/find_missing_transcriptions.py

Dependência:
    python -m pip install mysql-connector-python

O script lê o .env na raiz do projecto, não altera a base de dados e gera:
    reports/palavras_em_falta.txt
    reports/palavras_em_falta.csv
    reports/estatisticas.json
"""
from __future__ import annotations

import csv
import json
import os
import re
import sys
import unicodedata
from collections import Counter
from pathlib import Path
from typing import Any

try:
    import mysql.connector
    from mysql.connector import Error as MySQLError
except ImportError:
    print(
        "Falta instalar a dependência mysql-connector-python.\n"
        "Execute: python -m pip install mysql-connector-python",
        file=sys.stderr,
    )
    raise SystemExit(2)


ROOT = Path(__file__).resolve().parent.parent
ENV_FILE = ROOT / ".env"
BOOKS_LIST_CANDIDATES = [
    ROOT / "Books" / "Books.json",
    ROOT / "Books.json",
]
BOOK_CONTENT_DIR_CANDIDATES = [
    ROOT / "Books" / "contentBook",
    ROOT / "Books" / "ContentBook",
    ROOT / "contentBook",
    ROOT,
]
REPORTS_DIR = ROOT / "reports"

# Extrai sequências de letras Unicode, incluindo palavras com apóstrofos,
# como don't e God's. Hífens separam palavras (stead-fast -> stead, fast).
WORD_RE = re.compile(r"[^\W\d_]+(?:['’][^\W\d_]+)*", flags=re.UNICODE)


def load_env(path: Path) -> dict[str, str]:
    """Lê um .env simples, sem exigir python-dotenv."""
    values: dict[str, str] = {}
    if not path.is_file():
        raise FileNotFoundError(f"Não encontrei o ficheiro de configuração: {path}")

    for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
            value = value[1:-1]
        # Permite comentários no fim de valores não delimitados por aspas.
        elif " #" in value:
            value = value.split(" #", 1)[0].rstrip()
        values[key] = value
    return values


def first_existing(paths: list[Path], description: str) -> Path:
    for path in paths:
        if path.exists():
            return path
    options = "\n".join(f"  - {p}" for p in paths)
    raise FileNotFoundError(
        f"Não encontrei {description}. Caminhos verificados:\n{options}"
    )


def book_names_from_json(path: Path) -> list[str]:
    data = json.loads(path.read_text(encoding="utf-8-sig"))

    # Aceita listas directas ou listas embrulhadas em books/Books/data.
    for key in ("books", "Books", "data"):
        if isinstance(data, dict) and isinstance(data.get(key), list):
            data = data[key]
            break

    if not isinstance(data, list):
        raise ValueError(
            f"{path} não contém uma lista de livros reconhecível. "
            "Esperava uma lista ou uma propriedade books/Books/data."
        )

    names: list[str] = []
    for item in data:
        if isinstance(item, str):
            name = item.strip()
        elif isinstance(item, dict):
            name = str(
                item.get("book") or item.get("name") or item.get("title") or ""
            ).strip()
        else:
            name = ""
        if name and name not in names:
            names.append(name)

    if not names:
        raise ValueError(f"Não consegui extrair nomes de livros de {path}")
    return names


def find_book_json(book_name: str, content_dir: Path) -> Path | None:
    """Localiza o JSON do livro, mesmo quando o nome do ficheiro varia em caixa."""
    candidates = [
        content_dir / f"{book_name}.json",
        content_dir / f"{book_name.replace(' ', '_')}.json",
        content_dir / f"{book_name.replace(' ', '-')}.json",
    ]
    for path in candidates:
        if path.is_file():
            return path

    # Comparação sem distinguir maiúsculas/minúsculas.
    if content_dir.is_dir():
        wanted = {f"{book_name}.json".casefold(),
                  f"{book_name.replace(' ', '_')}.json".casefold(),
                  f"{book_name.replace(' ', '-')}.json".casefold()}
        for path in content_dir.glob("*.json"):
            if path.name.casefold() in wanted:
                return path

        # Alguns projectos guardam o nome canónico dentro do próprio JSON.
        for path in content_dir.glob("*.json"):
            if path.name.casefold() == "books.json":
                continue
            try:
                data = json.loads(path.read_text(encoding="utf-8-sig"))
            except (OSError, json.JSONDecodeError):
                continue
            if isinstance(data, dict):
                internal_name = str(data.get("book") or data.get("name") or "").strip()
                if internal_name.casefold() == book_name.casefold():
                    return path
    return None


def iter_verse_texts(data: Any):
    """Suporta a estrutura usada pelo migrate.php: chapters -> verses -> text."""
    if not isinstance(data, dict):
        return

    chapters = data.get("chapters")
    if not isinstance(chapters, list):
        return

    for chapter in chapters:
        if not isinstance(chapter, dict):
            continue
        verses = chapter.get("verses")
        if not isinstance(verses, list):
            continue
        for verse in verses:
            if isinstance(verse, dict):
                text = verse.get("text")
                if isinstance(text, str) and text:
                    yield text


def normalize_word(word: str) -> str:
    # NFC mantém caracteres Unicode consistentes; casefold é mais robusto que lower.
    return unicodedata.normalize("NFC", word).casefold().replace("’", "'")


def main() -> int:
    try:
        env = load_env(ENV_FILE)
        books_list_path = first_existing(BOOKS_LIST_CANDIDATES, "Books.json")

        content_dir = next(
            (p for p in BOOK_CONTENT_DIR_CANDIDATES if p.is_dir()),
            None,
        )
        if content_dir is None:
            raise FileNotFoundError(
                "Não encontrei a pasta dos JSON dos livros. "
                "Verifique Books/contentBook/."
            )

        book_names = book_names_from_json(books_list_path)

        db_config = {
            "host": env.get("DB_HOST", "127.0.0.1"),
            "port": int(env.get("DB_PORT", "3306")),
            "database": env.get("DB_DATABASE", ""),
            "user": env.get("DB_USERNAME", ""),
            "password": env.get("DB_PASSWORD", ""),
            "charset": env.get("DB_CHARSET", "utf8mb4"),
            "connection_timeout": 15,
        }
        if not db_config["database"] or not db_config["user"]:
            raise ValueError(
                "O .env precisa de ter DB_DATABASE e DB_USERNAME preenchidos."
            )

        print(f"Lista de livros: {books_list_path.relative_to(ROOT)}")
        print(f"Pasta dos livros: {content_dir.relative_to(ROOT)}")
        print("A ligar ao MySQL...")

        conn = mysql.connector.connect(**db_config)
        try:
            cursor = conn.cursor()
            cursor.execute(
                "SELECT original_word, transcription FROM transcription_table"
            )
            dictionary: dict[str, str] = {}
            for original_word, transcription in cursor.fetchall():
                key = normalize_word(str(original_word))
                # Se houver duplicados equivalentes em caixa, mantém a primeira entrada.
                dictionary.setdefault(key, str(transcription or "").strip())
            cursor.close()
        finally:
            conn.close()

        word_counts: Counter[str] = Counter()
        display_forms: dict[str, str] = {}
        books_processed = 0
        books_missing: list[str] = []
        json_errors: list[str] = []
        verses_processed = 0

        for index, book_name in enumerate(book_names, start=1):
            path = find_book_json(book_name, content_dir)
            if path is None:
                books_missing.append(book_name)
                print(f"[AVISO] JSON não encontrado para: {book_name}")
                continue

            try:
                data = json.loads(path.read_text(encoding="utf-8-sig"))
            except (OSError, json.JSONDecodeError) as exc:
                json_errors.append(f"{path.name}: {exc}")
                print(f"[AVISO] Não foi possível ler {path.name}: {exc}")
                continue

            verse_count_for_book = 0
            for verse_text in iter_verse_texts(data):
                verses_processed += 1
                verse_count_for_book += 1
                for match in WORD_RE.finditer(verse_text):
                    original = match.group(0)
                    key = normalize_word(original)
                    if not key:
                        continue
                    word_counts[key] += 1
                    display_forms.setdefault(key, original)

            books_processed += 1
            print(
                f"[{index}/{len(book_names)}] {book_name}: "
                f"{verse_count_for_book} versículos"
            )

        missing: list[tuple[str, str, int]] = []
        for key, occurrences in word_counts.items():
            transcription = dictionary.get(key)
            if transcription is None:
                status = "não existe no dicionário"
            elif not transcription or transcription == "---":
                status = "transcrição vazia ou ---"
            else:
                continue
            missing.append((display_forms[key], status, occurrences))

        # Ordenação previsível e útil para revisão manual.
        missing.sort(key=lambda row: normalize_word(row[0]))

        REPORTS_DIR.mkdir(parents=True, exist_ok=True)
        txt_path = REPORTS_DIR / "palavras_em_falta.txt"
        csv_path = REPORTS_DIR / "palavras_em_falta.csv"
        stats_path = REPORTS_DIR / "estatisticas.json"

        with txt_path.open("w", encoding="utf-8", newline="\n") as file:
            for word, _, _ in missing:
                file.write(word + "\n")

        with csv_path.open("w", encoding="utf-8-sig", newline="") as file:
            writer = csv.writer(file)
            writer.writerow(["original_word", "situacao", "ocorrencias_na_biblia"])
            writer.writerows(missing)

        missing_in_db = sum(1 for _, status, _ in missing
                            if status == "não existe no dicionário")
        no_transcription = len(missing) - missing_in_db
        stats = {
            "books_list_file": str(books_list_path.relative_to(ROOT)),
            "book_json_directory": str(content_dir.relative_to(ROOT)),
            "books_listed": len(book_names),
            "books_processed": books_processed,
            "books_missing": books_missing,
            "json_errors": json_errors,
            "verses_processed": verses_processed,
            "unique_words_in_bible": len(word_counts),
            "dictionary_entries_loaded": len(dictionary),
            "unique_words_missing_transcription": len(missing),
            "missing_from_dictionary": missing_in_db,
            "empty_or_dash_transcription": no_transcription,
            "reports": [
                str(txt_path.relative_to(ROOT)),
                str(csv_path.relative_to(ROOT)),
                str(stats_path.relative_to(ROOT)),
            ],
        }
        stats_path.write_text(
            json.dumps(stats, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

        print("\nAnálise concluída.")
        print(f"Livros processados: {books_processed}/{len(book_names)}")
        print(f"Versículos processados: {verses_processed}")
        print(f"Palavras únicas encontradas nos textos: {len(word_counts)}")
        print(f"Palavras únicas sem transcrição: {len(missing)}")
        print(f"  - Ausentes do dicionário: {missing_in_db}")
        print(f"  - Com transcrição vazia ou ---: {no_transcription}")
        print(f"Relatório TXT: {txt_path}")
        print(f"Relatório CSV: {csv_path}")
        print(f"Estatísticas: {stats_path}")

        if books_missing or json_errors:
            print(
                "\nATENÇÃO: a análise está incompleta. Corrija os avisos acima "
                "antes de considerar a lista como a Bíblia inteira."
            )
            return 1
        return 0

    except (FileNotFoundError, ValueError, OSError, json.JSONDecodeError) as exc:
        print(f"Erro: {exc}", file=sys.stderr)
        return 1
    except MySQLError as exc:
        print(f"Erro de ligação/consulta MySQL: {exc}", file=sys.stderr)
        print(
            "Confirme as credenciais no .env e se transcription_table existe.",
            file=sys.stderr,
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
'''
path = Path("/mnt/data/find_missing_transcriptions.py")
path.write_text(script, encoding="utf-8")
print(f"Script criado: {path} ({len(script.splitlines())} linhas)")
