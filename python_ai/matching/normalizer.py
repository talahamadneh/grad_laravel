import re
from typing import Any


SKILL_ALIASES = {
    "amazon web services": "aws",
    "asp net": ".net",
    "asp.net": ".net",
    "c sharp": "c#",
    "c plus plus": "c++",
    "css3": "css",
    "dot net": ".net",
    "html5": "html",
    "java script": "javascript",
    "js": "javascript",
    "laravel framework": "laravel",
    "mongo db": "mongodb",
    "my sql": "mysql",
    "mysql database": "mysql",
    "node.js": "node",
    "nodejs": "node",
    "php laravel": "laravel",
    "postgre sql": "postgresql",
    "postgres": "postgresql",
    "react.js": "react",
    "reactjs": "react",
    "rest api": "rest api",
    "rest apis": "rest api",
    "restful api": "rest api",
    "ts": "typescript",
    "ui ux": "ui/ux",
}

MAJOR_ALIASES = {
    "computer engineer": "computer engineering",
    "computer engineering": "computer engineering",
    "computer science": "computer science",
    "cs": "computer science",
    "software engineer": "software engineering",
    "software engineering": "software engineering",
}


def clean_text(value: Any) -> str:
    if value is None:
        return ""

    text = str(value).lower().strip()
    text = text.replace("&", " and ")
    text = re.sub(r"[_/\\+-]+", " ", text)
    text = re.sub(r"[^\w\s.#]", " ", text)
    return " ".join(text.split())


def normalize_skill(value: Any) -> str:
    if isinstance(value, dict):
        value = value.get("name") or value.get("skill") or value.get("title") or ""

    text = clean_text(value)
    return SKILL_ALIASES.get(text, text)


def normalize_skills(values: list[Any] | None) -> list[str]:
    result = []
    for item in values or []:
        skill = normalize_skill(item)
        if skill and skill not in result:
            result.append(skill)
    return result


def normalize_major(value: Any) -> str:
    text = clean_text(value)
    return MAJOR_ALIASES.get(text, text)


def tokenize(value: Any) -> set[str]:
    text = clean_text(value)
    if not text:
        return set()

    stopwords = {
        "a",
        "an",
        "and",
        "for",
        "in",
        "of",
        "on",
        "or",
        "the",
        "to",
        "with",
        "using",
        "use",
        "build",
        "built",
        "developer",
        "development",
        "job",
        "role",
    }

    return {
        token
        for token in re.findall(r"[a-z0-9.#]+", text)
        if len(token) > 1 and token not in stopwords
    }

