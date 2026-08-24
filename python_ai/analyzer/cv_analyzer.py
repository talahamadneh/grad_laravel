from typing import Any

from .scoring import (
    score_ats,
    score_completeness,
    score_consistency,
    score_experience_projects,
    score_skills,
    score_summary,
)


def level_for(score: int) -> str:
    if score >= 90:
        return "Excellent"
    if score >= 75:
        return "Good"
    if score >= 60:
        return "Fair"

    return "Needs Improvement"


def unique_messages(messages: list[str]) -> list[str]:
    seen = set()
    result = []

    for message in messages:
        if message and message not in seen:
            result.append(message)
            seen.add(message)

    return result


def analyze_cv(cv: dict[str, Any]) -> dict[str, Any]:
    strengths: list[str] = []
    weaknesses: list[str] = []
    recommendations: list[str] = []

    completeness = score_completeness(cv, strengths, weaknesses)
    summary = score_summary(cv, strengths, weaknesses, recommendations)
    skills, normalized_skills = score_skills(cv, strengths, weaknesses, recommendations)
    experience_projects = score_experience_projects(cv, strengths, weaknesses, recommendations)
    ats_weighted, ats_score = score_ats(cv, normalized_skills, weaknesses, recommendations)
    consistency = score_consistency(cv, normalized_skills, weaknesses, recommendations)

    overall = completeness + summary + skills + experience_projects + ats_weighted + consistency

    if overall >= 70:
        recommendations.append("Keep tailoring the CV keywords to each job description.")
    else:
        recommendations.append("Focus first on completing missing sections, then improve descriptions.")

    return {
        "overall_score": max(0, min(100, overall)),
        "ats_score": ats_score,
        "level": level_for(overall),
        "strengths": unique_messages(strengths)[:8],
        "weaknesses": unique_messages(weaknesses)[:8],
        "recommendations": unique_messages(recommendations)[:8],
        "section_scores": {
            "completeness": completeness,
            "summary": summary,
            "skills": skills,
            "experience_projects": experience_projects,
            "ats": ats_weighted,
            "consistency": consistency,
        },
        "analysis_source": "local_python",
    }
