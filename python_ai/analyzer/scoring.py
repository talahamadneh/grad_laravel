from typing import Any
from datetime import datetime

from .skill_normalizer import normalize_skills
from .text_utils import (
    ACTION_VERBS,
    GENERIC_SKILLS,
    ROLE_FIELD_KEYWORDS,
    TECH_KEYWORDS,
    clean_text,
    contains_any,
    duplicate_count,
    entry_description,
    keyword_hits,
    text_has_measurable_impact,
    word_count,
)


def clamp(value: float, maximum: int) -> int:
    return max(0, min(maximum, round(value)))


def practical_metrics(cv: dict[str, Any]) -> dict[str, int]:
    entries = list(cv.get("experience") or []) + list(cv.get("projects") or [])
    descriptions = [entry_description(entry) for entry in entries]
    useful = [description for description in descriptions if word_count(description) >= 10]
    detailed = [description for description in descriptions if word_count(description) >= 16]
    action = [description for description in descriptions if contains_any(description, ACTION_VERBS)]
    tech = [description for description in descriptions if contains_any(description, TECH_KEYWORDS)]
    impact = [description for description in descriptions if text_has_measurable_impact(description)]
    unique_descriptions = {description.lower() for description in descriptions if description}

    return {
        "entries": len(entries),
        "useful": len(useful),
        "detailed": len(detailed),
        "action": len(action),
        "tech": len(tech),
        "impact": len(impact),
        "duplicates": len(descriptions) - len(unique_descriptions),
    }


def score_completeness(cv: dict[str, Any], strengths: list[str], weaknesses: list[str]) -> int:
    score = 0.0

    if word_count(cv.get("professional_title")) >= 2:
        score += 3
        strengths.append("Professional title is present.")
    elif clean_text(cv.get("professional_title")):
        score += 2
    else:
        weaknesses.append("Professional title is missing.")

    summary_words = word_count(cv.get("summary"))
    if summary_words >= 35 and contains_any(cv.get("summary"), ROLE_FIELD_KEYWORDS):
        score += 4
        strengths.append("Professional summary is present.")
    elif summary_words >= 20:
        score += 3
        strengths.append("Professional summary is present.")
    elif summary_words > 0:
        score += 1
        weaknesses.append("Professional summary is too short.")
    else:
        weaknesses.append("Professional summary is missing.")

    skills = normalize_skills(cv.get("skills") or [])
    unique_skills = sorted(set(skills))
    technical = [skill for skill in unique_skills if skill in TECH_KEYWORDS]
    if len(unique_skills) >= 6 and len(technical) >= 4:
        score += 4
        strengths.append("Skills section is present.")
    elif len(unique_skills) >= 4 and len(technical) >= 2:
        score += 3
        strengths.append("Skills section is present.")
    elif unique_skills:
        score += 1.5
        weaknesses.append("Skills section needs more useful technical detail.")
    else:
        weaknesses.append("Skills section is missing.")

    education = cv.get("education") or []
    meaningful_education = [
        item for item in education
        if isinstance(item, dict)
        and clean_text(item.get("degree") or item.get("major") or item.get("field_of_study"))
        and clean_text(item.get("university") or item.get("institution") or item.get("school"))
    ]
    if meaningful_education:
        score += 3
        strengths.append("Education information is present.")
    elif education:
        score += 2
    else:
        weaknesses.append("Education information is missing.")

    practical = practical_metrics(cv)
    if practical["entries"] >= 2 and practical["useful"] >= 2 and practical["action"] >= 1 and practical["tech"] >= 1:
        score += 4
        strengths.append("Experience or project information is included.")
    elif practical["entries"] >= 1 and practical["useful"] >= 1 and practical["tech"] >= 1:
        score += 2.5
        strengths.append("Experience or project information is included.")
        weaknesses.append("Practical experience evidence is present but still limited.")
    elif practical["entries"] >= 1:
        score += 1
        weaknesses.append("Project or experience entries need more meaningful descriptions.")
    else:
        weaknesses.append("Add experience or projects to show practical work.")

    contact = cv.get("contact") or {}
    has_email = bool(clean_text(contact.get("email")))
    has_phone = bool(clean_text(contact.get("phone")))
    has_profile = any(clean_text(contact.get(key)) for key in ("linkedin", "github", "portfolio"))
    if has_email and has_profile:
        score += 2
    elif has_email and has_phone:
        score += 1.5
    elif has_email:
        score += 1
    else:
        weaknesses.append("Contact or professional profile links are missing.")

    return clamp(score, 20)


def score_summary(cv: dict[str, Any], strengths: list[str], weaknesses: list[str], recommendations: list[str]) -> int:
    summary = clean_text(cv.get("summary"))
    words = word_count(summary)
    score = 0

    if not summary:
        weaknesses.append("Professional summary is missing.")
        recommendations.append("Add a 3-4 sentence professional summary focused on role, skills, and goals.")
        return 0

    if 35 <= words <= 90:
        score += 5
        strengths.append("Professional summary has a reasonable length.")
    elif words < 20:
        score += 1
        weaknesses.append("Professional summary is too short.")
        recommendations.append("Expand the professional summary with technical focus and career direction.")
    elif words <= 34:
        score += 4
        strengths.append("Professional summary is concise and usable.")
    else:
        score += 3
        weaknesses.append("Professional summary may be too long.")

    technical_hits = keyword_hits(summary, TECH_KEYWORDS)
    if technical_hits:
        score += 4
        strengths.append("Summary mentions relevant professional or technical keywords.")
    else:
        weaknesses.append("Summary does not mention enough professional or technical keywords.")

    if contains_any(summary, ROLE_FIELD_KEYWORDS):
        score += 3
    else:
        recommendations.append("Mention the target field or role in the professional summary.")

    repeated_keyword_count = sum(summary.lower().count(keyword) for keyword in technical_hits)
    if words > 120 or repeated_keyword_count > max(6, len(technical_hits) * 2):
        weaknesses.append("Summary may be keyword-stuffed or too long.")
        recommendations.append("Keep the summary focused and avoid repeating keywords unnaturally.")
    elif not contains_any(summary, {"hard working", "motivated individual", "team player"}):
        score += 3
    else:
        weaknesses.append("Summary contains generic wording.")

    return clamp(score, 15)


def score_skills(cv: dict[str, Any], strengths: list[str], weaknesses: list[str], recommendations: list[str]) -> tuple[int, list[str]]:
    skills = normalize_skills(cv.get("skills") or [])
    unique_skills = sorted(set(skills))
    duplicates = duplicate_count(skills)
    technical = [skill for skill in unique_skills if skill in TECH_KEYWORDS]
    generic = [skill for skill in unique_skills if skill in GENERIC_SKILLS]

    score = 0

    if len(unique_skills) >= 5:
        score += 4
        strengths.append("Skills section has enough distinct skills.")
    elif len(unique_skills) >= 3:
        score += 3
    elif unique_skills:
        score += 1
        weaknesses.append("Skills section is limited.")
    else:
        weaknesses.append("Skills section is missing.")
        recommendations.append("Add relevant technical and professional skills.")

    if len(technical) >= 5:
        score += 8
        strengths.append("Skills section includes relevant technical skills.")
    elif len(technical) >= 3:
        score += 6
    elif len(technical) >= 2:
        score += 3
    else:
        weaknesses.append("Skills section needs more role-relevant technical skills.")

    technical_ratio = len(technical) / max(len(unique_skills), 1)
    generic_ratio = len(generic) / max(len(unique_skills), 1)

    if technical_ratio >= 0.6 and len(technical) >= 3:
        score += 4
    elif technical_ratio >= 0.35:
        score += 2
    else:
        weaknesses.append("Technical skills are diluted by generic or unclear skills.")

    skill_families = {
        "frontend": {"react", "html", "css", "javascript", "typescript", "tailwind", "bootstrap", "vue"},
        "backend": {"php", "laravel", "python", "java", "node", "api", "rest api", ".net", "c#", "c++", "c"},
        "database": {"mysql", "postgresql", "mongodb", "sql", "database", "firebase"},
        "tools_cloud": {"git", "github", "docker", "linux", "aws", "azure", "cloud", "devops"},
        "quality": {"qa", "testing"},
    }
    diversity = sum(1 for family in skill_families.values() if family.intersection(unique_skills))
    if diversity >= 3:
        score += 3
        strengths.append("Skills show useful technical diversity.")
    elif diversity >= 2:
        score += 2
    elif unique_skills:
        score += 1

    penalty = 0
    if duplicates:
        penalty += min(4, duplicates)
        weaknesses.append("Duplicate skills were detected.")
        recommendations.append("Remove duplicated skills and keep one normalized version of each skill.")

    if generic_ratio > 0.35 or len(generic) > 4:
        penalty += 3
        weaknesses.append("Skills section relies too much on generic skills.")

    if len(unique_skills) > 15:
        penalty += min(4, len(unique_skills) - 15)
        weaknesses.append("Skills list may be too broad or keyword-stuffed.")
        recommendations.append("Keep the skills section focused on the most relevant tools and abilities.")

    score -= penalty

    return clamp(score, 20), skills


def score_experience_projects(cv: dict[str, Any], strengths: list[str], weaknesses: list[str], recommendations: list[str]) -> int:
    entries = list(cv.get("experience") or []) + list(cv.get("projects") or [])

    if not entries:
        weaknesses.append("No experience or project entries were found.")
        recommendations.append("Add at least two projects or experience entries with clear descriptions.")
        return 0

    metrics = practical_metrics(cv)

    score = 0
    score += 3
    score += min(7, metrics["useful"] * 3)
    score += min(4, metrics["action"] * 2)
    score += min(3, metrics["tech"] * 2)
    score += min(3, metrics["impact"] * 2)
    score -= min(4, metrics["duplicates"] * 2)

    if metrics["useful"] == len(entries):
        strengths.append("Experience and project descriptions are detailed.")
    else:
        weaknesses.append("Some experience or project descriptions are too short.")

    if metrics["action"]:
        strengths.append("Descriptions use action verbs.")
    else:
        weaknesses.append("Experience and project descriptions need stronger action verbs.")

    if not metrics["impact"]:
        weaknesses.append("No measurable achievements are mentioned.")
        recommendations.append("Add measurable results where they are true, such as users, percentages, or outcomes.")

    if metrics["duplicates"]:
        weaknesses.append("Repeated project or experience descriptions were detected.")

    return clamp(score, 20)


def score_ats(cv: dict[str, Any], normalized_skills: list[str], weaknesses: list[str], recommendations: list[str]) -> tuple[int, int]:
    score = 0
    unique_skills = sorted(set(normalized_skills))
    technical = [skill for skill in unique_skills if skill in TECH_KEYWORDS]
    all_text = clean_text(cv)
    summary_words = word_count(cv.get("summary"))
    practical = practical_metrics(cv)
    contact = cv.get("contact") or {}

    if clean_text(cv.get("professional_title")):
        score += 7
    if 35 <= summary_words <= 90 and contains_any(cv.get("summary"), ROLE_FIELD_KEYWORDS) and contains_any(cv.get("summary"), TECH_KEYWORDS):
        score += 14
    elif 25 <= summary_words <= 90 and contains_any(cv.get("summary"), ROLE_FIELD_KEYWORDS):
        score += 10
    elif 15 <= summary_words <= 120:
        score += 6
    if len(unique_skills) >= 5:
        score += 8
    elif len(unique_skills) >= 3:
        score += 5
    if len(technical) >= 5:
        score += 13
    elif len(technical) >= 3:
        score += 9
    elif len(technical) >= 1:
        score += 4
    if cv.get("education"):
        score += 8

    if practical["entries"] >= 2 and practical["useful"] >= 2 and practical["action"] >= 1 and practical["tech"] >= 1:
        score += 13
    elif practical["entries"] >= 1 and practical["useful"] >= 1 and practical["action"] >= 1 and practical["tech"] >= 1:
        score += 8
    elif practical["entries"] >= 1:
        score += 4

    score += min(4, practical["impact"] * 2)

    if contains_any(all_text, TECH_KEYWORDS) and contains_any(all_text, ROLE_FIELD_KEYWORDS):
        score += 6
    if len(all_text) >= 500:
        score += 5
    elif len(all_text) >= 250:
        score += 3

    if clean_text(contact.get("email")):
        score += 2
    if clean_text(contact.get("phone")):
        score += 1
    has_profile = any(clean_text(contact.get(key)) for key in ("linkedin", "github", "portfolio"))
    if has_profile:
        score += 4

    if practical["entries"] >= 2 and practical["useful"] >= 2 and practical["action"] >= 2 and practical["tech"] >= 2:
        score += 6
    if has_profile and bool(cv.get("certificates")):
        score += 3
    if has_profile and bool(cv.get("languages")):
        score += 2

    if len([paragraph for paragraph in all_text.split(".") if word_count(paragraph) > 90]) > 0:
        score -= 8
        weaknesses.append("Some paragraphs may be too long for easy scanning.")

    if len(set(normalized_skills)) != len(normalized_skills):
        score -= 8

    unusual_symbol_count = len([char for char in all_text if not char.isalnum() and not char.isspace() and char not in ".,:;#/+@()-"])
    if unusual_symbol_count > 12:
        score -= 6
        recommendations.append("Reduce unusual symbols to improve estimated ATS readability.")

    if summary_words > 120:
        score -= 12
    if len(unique_skills) > 18:
        score -= min(10, len(unique_skills) - 18)

    ats_score = clamp(score, 100)
    has_measurable_impact = any(
        text_has_measurable_impact(entry_description(entry))
        for entry in list(cv.get("experience") or []) + list(cv.get("projects") or [])
    )
    has_exceptional_evidence = (
        has_measurable_impact
        and bool(cv.get("certificates"))
        and bool(cv.get("languages"))
        and len(set(normalized_skills)) == len(normalized_skills)
        and unusual_symbol_count <= 6
    )
    if practical["entries"] < 2 and ats_score > 82:
        ats_score = 82
    if not has_profile and ats_score > 88:
        ats_score = 88
    if ats_score > 95 and not has_exceptional_evidence:
        ats_score = 95

    weighted_score = clamp(ats_score * 0.15, 15)

    return weighted_score, ats_score


def score_consistency(cv: dict[str, Any], normalized_skills: list[str], weaknesses: list[str], recommendations: list[str]) -> int:
    score = 10

    if duplicate_count(normalized_skills) > 0:
        score -= 2

    entries = list(cv.get("experience") or []) + list(cv.get("projects") or []) + list(cv.get("education") or [])
    seen_entries = set()
    has_date_issue = False

    for entry in entries:
        text = clean_text(entry).lower()
        if not text:
            score -= 1
            weaknesses.append("Some CV entries are empty.")
            continue

        if text in seen_entries:
            score -= 2
            weaknesses.append("Duplicate CV entries were detected.")

        seen_entries.add(text)

        if isinstance(entry, dict) and any(key in entry for key in ("start_date", "end_date")):
            start = clean_text(entry.get("start_date"))
            end = clean_text(entry.get("end_date"))

            if not start and not end:
                score -= 1
                has_date_issue = True
            elif start and end:
                start_date = parse_flexible_date(start)
                end_date = parse_flexible_date(end)

                if not start_date or not end_date:
                    score -= 1
                    has_date_issue = True
                elif start_date > end_date:
                    score -= 2
                    has_date_issue = True

    if score < 10:
        recommendations.append("Review repeated or incomplete entries for better consistency.")

    if has_date_issue:
        weaknesses.append("Some dates are missing, invalid, or inconsistent.")
        recommendations.append("Review inconsistent or invalid dates in education or experience entries.")

    return clamp(score, 10)


def parse_flexible_date(value: str) -> datetime | None:
    cleaned = clean_text(value).lower()
    if cleaned in {"present", "current", "now"}:
        return datetime.now()

    for date_format in ("%Y-%m-%d", "%Y/%m/%d", "%Y-%m", "%Y/%m", "%m/%Y", "%b %Y", "%B %Y", "%Y"):
        try:
            return datetime.strptime(cleaned.title(), date_format)
        except ValueError:
            continue

    return None
