from typing import Any


def generate_candidate_summary(payload: dict[str, Any]) -> dict[str, str]:
    job_title = clean_text(payload.get("job_title")) or "the role"
    match_percentage = clamp_percentage(payload.get("match_percentage"))
    matching_skills = clean_list(payload.get("matching_skills"))
    missing_skills = clean_list(payload.get("missing_skills"))
    experience = clean_items(payload.get("relevant_experience"))
    projects = clean_items(payload.get("relevant_projects"))

    parts = [
        suitability_sentence(match_percentage, job_title),
        matching_skills_sentence(matching_skills),
    ]

    background = background_sentence(experience, projects)
    if background:
        parts.append(background)

    if missing_skills:
        parts.append(
            f"{join_list(missing_skills)} should be verified during the interview as missing or unclear requirements."
        )

    parts.append(recommendation_sentence(match_percentage, missing_skills))

    return {"summary": " ".join(parts)}


def suitability_sentence(match_percentage: int, job_title: str) -> str:
    if match_percentage >= 75:
        strength = "a strong"
    elif match_percentage >= 50:
        strength = "a moderate"
    else:
        strength = "a developing"

    return f"The candidate shows {strength} {match_percentage}% match for the {job_title} role."


def matching_skills_sentence(skills: list[str]) -> str:
    if not skills:
        return "No direct matching technical skills are currently identified from the supplied data."

    return f"Relevant matching skills include {join_list(skills)}."


def background_sentence(experience: list[str], projects: list[str]) -> str | None:
    if experience and projects:
        return (
            f"Their experience in {join_list(experience)} and project work on {join_list(projects)} "
            "support the technical evaluation."
        )

    if experience:
        return f"Their experience in {join_list(experience)} supports the technical evaluation."

    if projects:
        return f"Their project work on {join_list(projects)} supports the technical evaluation."

    return None


def recommendation_sentence(match_percentage: int, missing_skills: list[str]) -> str:
    if match_percentage >= 75 and not missing_skills:
        return "Overall, the candidate appears suitable to proceed to the interview stage."

    if match_percentage >= 50:
        return "Overall, the candidate may be suitable to proceed, with the interview used to confirm the open requirements."

    return "Overall, the candidate should be reviewed cautiously, with the interview focused on validating role-critical requirements."


def clean_text(value: Any) -> str:
    if value is None:
        return ""

    return " ".join(str(value).strip().split())


def clean_list(value: Any) -> list[str]:
    if not isinstance(value, list):
        return []

    cleaned: list[str] = []
    seen: set[str] = set()
    for item in value:
        text = display_skill_name(clean_text(item))
        key = text.lower()
        if text and key not in seen:
            cleaned.append(text)
            seen.add(key)

    return cleaned[:5]


def display_skill_name(value: str) -> str:
    canonical = {
        "api": "API",
        "apis": "APIs",
        "aws": "AWS",
        "css": "CSS",
        "html": "HTML",
        "javascript": "JavaScript",
        "js": "JS",
        "json": "JSON",
        "laravel": "Laravel",
        "mysql": "MySQL",
        "php": "PHP",
        "rest": "REST",
        "sql": "SQL",
        "typescript": "TypeScript",
    }

    parts = value.split(" ")
    normalized = [canonical.get(part.lower(), part) for part in parts]

    return " ".join(normalized)


def clean_items(value: Any) -> list[str]:
    if not isinstance(value, list):
        return []

    cleaned: list[str] = []
    seen: set[str] = set()
    for item in value:
        text = item_label(item)
        key = text.lower()
        if text and key not in seen:
            cleaned.append(text)
            seen.add(key)

    return cleaned[:3]


def item_label(item: Any) -> str:
    if isinstance(item, dict):
        for key in ("title", "position", "name", "role"):
            text = clean_text(item.get(key))
            if text:
                return text

        description = clean_text(item.get("description"))
        if description:
            return description[:80].rstrip()

        return ""

    return clean_text(item)


def clamp_percentage(value: Any) -> int:
    try:
        percentage = round(float(value))
    except (TypeError, ValueError):
        percentage = 0

    return max(0, min(100, int(percentage)))


def join_list(items: list[str]) -> str:
    if not items:
        return ""

    if len(items) == 1:
        return items[0]

    if len(items) == 2:
        return f"{items[0]} and {items[1]}"

    return f"{', '.join(items[:-1])}, and {items[-1]}"
