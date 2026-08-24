import re
from collections import Counter
from typing import Any


ACTION_VERBS = {
    "achieved",
    "built",
    "created",
    "deployed",
    "designed",
    "developed",
    "implemented",
    "improved",
    "integrated",
    "managed",
    "optimized",
    "tested",
}

TECH_KEYWORDS = {
    "api",
    "aws",
    "azure",
    "bootstrap",
    "c",
    "c#",
    "c++",
    "cloud",
    "css",
    "database",
    "devops",
    "docker",
    "fastapi",
    "firebase",
    "flask",
    "git",
    "github",
    "html",
    "java",
    "javascript",
    "laravel",
    "linux",
    "mongodb",
    "mysql",
    "node",
    ".net",
    "php",
    "postgresql",
    "python",
    "qa",
    "react",
    "rest",
    "rest api",
    "sql",
    "tailwind",
    "testing",
    "typescript",
    "vue",
}

ROLE_FIELD_KEYWORDS = {
    "analyst",
    "artificial intelligence",
    "backend",
    "cloud",
    "computer engineering",
    "computer science",
    "cybersecurity",
    "data",
    "database",
    "designer",
    "developer",
    "devops",
    "embedded",
    "engineer",
    "frontend",
    "full stack",
    "graduate",
    "information technology",
    "intern",
    "machine learning",
    "mobile",
    "network",
    "project management",
    "qa",
    "software",
    "student",
    "testing",
    "ui/ux",
    "web",
}

GENERIC_SKILLS = {
    "communication",
    "computer",
    "creative",
    "critical thinking",
    "fast learner",
    "flexible",
    "hard working",
    "leadership",
    "microsoft office",
    "office",
    "problem solving",
    "punctual",
    "time management",
    "teamwork",
    "word",
}


def clean_text(value: Any) -> str:
    if value is None:
        return ""

    if isinstance(value, list):
        return " ".join(clean_text(item) for item in value)

    if isinstance(value, dict):
        return " ".join(clean_text(item) for item in value.values())

    return re.sub(r"\s+", " ", str(value)).strip()


def word_count(value: Any) -> int:
    return len(re.findall(r"[A-Za-z0-9+#.]+", clean_text(value)))


def contains_any(text: str, keywords: set[str]) -> bool:
    lowered = clean_text(text).lower()

    return any(has_keyword(lowered, keyword) for keyword in keywords)


def has_keyword(text: str, keyword: str) -> bool:
    lowered = clean_text(text).lower()
    normalized_keyword = re.escape(keyword.lower()).replace(r"\ ", r"\s+")

    return bool(re.search(rf"(?<![a-z0-9+#.]){normalized_keyword}(?![a-z0-9+#.])", lowered))


def keyword_hits(text: str, keywords: set[str]) -> set[str]:
    lowered = clean_text(text).lower()

    return {keyword for keyword in keywords if has_keyword(lowered, keyword)}


def duplicate_count(values: list[str]) -> int:
    normalized = [value.strip().lower() for value in values if value.strip()]
    counts = Counter(normalized)

    return sum(count - 1 for count in counts.values() if count > 1)


def text_has_measurable_impact(text: str) -> bool:
    lowered = clean_text(text).lower()

    result_verbs = r"(improved|reduced|increased|decreased|raised|lowered|cut|saved|supported|served|used by|handled|processed|delivered|achieved|boosted)"
    impact_objects = r"(performance|response time|processing time|accuracy|users|students|clients|customers|requests|records|sales|cost|errors|load time|conversion|revenue)"
    number = r"(\d+(\.\d+)?\s*(%|percent|users?|students?|clients?|customers?|requests?|records?|seconds?|minutes?|hours?)?)"

    positive_patterns = [
        rf"{result_verbs}[^.]*{number}",
        rf"{result_verbs}[^.]*{impact_objects}",
        rf"{impact_objects}[^.]*{number}",
        rf"from\s+\d+[^.]*\s+to\s+\d+",
    ]

    weak_contexts = [
        r"worked for \d+\s*(months?|years?)",
        r"used \d+\s*(programming )?languages?",
        r"completed in \d{4}",
        r"team of \d+",
        r"\b\d{4}\b",
    ]

    if any(re.search(pattern, lowered) for pattern in positive_patterns):
        return not any(re.fullmatch(pattern, lowered.strip()) for pattern in weak_contexts)

    return False


def entry_description(entry: Any) -> str:
    if isinstance(entry, dict):
        return clean_text(
            entry.get("description")
            or entry.get("summary")
            or entry.get("responsibilities")
            or entry.get("details")
            or ""
        )

    return clean_text(entry)
