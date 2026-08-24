from typing import Any

from .text_utils import clean_text


SKILL_ALIASES = {
    "amazon web services": "aws",
    "asp.net": ".net",
    "c sharp": "c#",
    "c plus plus": "c++",
    "dot net": ".net",
    "github": "github",
    "java script": "javascript",
    "js": "javascript",
    "ts": "typescript",
    "react.js": "react",
    "reactjs": "react",
    "laravel framework": "laravel",
    "php laravel": "laravel",
    "node.js": "node",
    "nodejs": "node",
    "restful api": "rest api",
    "rest apis": "rest api",
    "my sql": "mysql",
    "mongo db": "mongodb",
    "postgre sql": "postgresql",
    "postgres": "postgresql",
    "html5": "html",
    "css3": "css",
    "ui ux": "ui/ux",
}


def extract_skill_name(skill: Any) -> str:
    if isinstance(skill, dict):
        return clean_text(skill.get("name") or skill.get("skill") or skill.get("title") or "")

    return clean_text(skill)


def normalize_skill(skill: Any) -> str:
    name = extract_skill_name(skill).lower().strip()
    name = name.replace("_", " ")
    name = " ".join(name.split())

    return SKILL_ALIASES.get(name, name)


def normalize_skills(skills: list[Any]) -> list[str]:
    return [skill for skill in (normalize_skill(item) for item in skills or []) if skill]
