from __future__ import annotations

from typing import Any

from .normalizer import clean_text, normalize_major, normalize_skills, tokenize


BASE_WEIGHTS = {
    "skills": 45.0,
    "experience": 20.0,
    "major": 15.0,
    "projects": 10.0,
    "preferences": 10.0,
}

EXPERIENCE_NEAR_MISS_YEARS = 0.5


def match_job(payload: dict[str, Any]) -> dict[str, Any]:
    student = payload.get("student") or {}
    job = payload.get("job") or {}
    reasons: list[str] = []
    warnings: list[str] = []

    breakdown = {
        "skills": _skills_score(student, job, reasons, warnings),
        "experience": _experience_score(student, job, reasons, warnings),
        "major": _major_score(student, job, reasons, warnings),
        "projects": _projects_score(student, job, reasons, warnings),
        "preferences": _preferences_score(student, job, reasons, warnings),
    }

    applicable_weight = sum(item["max_weight"] for item in breakdown.values() if item["applicable"])
    earned = sum(item["score"] for item in breakdown.values() if item["applicable"])
    final_score = round((earned / applicable_weight) * 100) if applicable_weight else 0
    final_score = max(0, min(100, final_score))

    return {
        "score": final_score,
        "level": _level(final_score),
        "breakdown": breakdown,
        "matching_skills": breakdown["skills"].get("matching_skills", []),
        "missing_skills": breakdown["skills"].get("missing_skills", []),
        "reasons": reasons,
        "warnings": warnings,
    }


def _skills_score(student: dict[str, Any], job: dict[str, Any], reasons: list[str], warnings: list[str]) -> dict[str, Any]:
    job_skills = normalize_skills(job.get("skills"))
    if not job_skills:
        return _not_applicable("skills", "Job does not specify required skills.")

    resume = student.get("resume") or {}
    student_skills = normalize_skills(resume.get("skills")) + normalize_skills(student.get("skills"))
    student_skills = list(dict.fromkeys(student_skills))

    if not student_skills:
        warnings.append("Student skill data is missing.")

    evidence_text = " ".join(_entry_text(item) for item in (resume.get("projects") or []) + (resume.get("experience") or []))
    evidence_tokens = tokenize(evidence_text)

    matching = []
    supported = []
    missing = []

    for skill in job_skills:
        skill_tokens = tokenize(skill)
        if skill in student_skills:
            matching.append(skill)
        elif skill_tokens and skill_tokens.issubset(evidence_tokens):
            supported.append(skill)
        else:
            missing.append(skill)

    direct_points = len(matching)
    support_points = len(supported) * 0.5
    ratio = min(1.0, (direct_points + support_points) / len(job_skills))
    score = round(BASE_WEIGHTS["skills"] * ratio, 2)

    if matching:
        reasons.append("Matched required skills: " + ", ".join(matching))

    if supported:
        reasons.append("Project or experience text supports: " + ", ".join(supported))

    return {
        "score": score,
        "max_weight": BASE_WEIGHTS["skills"],
        "applicable": True,
        "status": "scored",
        "matching_skills": matching + supported,
        "missing_skills": missing,
    }


def _experience_score(student: dict[str, Any], job: dict[str, Any], reasons: list[str], warnings: list[str]) -> dict[str, Any]:
    minimum = _number(job.get("min_experience_years"))
    maximum = _number(job.get("max_experience_years"))

    if minimum is None and maximum is None:
        return _not_applicable("experience", "Job does not specify experience years.")

    years = _number((student.get("resume") or {}).get("total_years_experience"))
    if years is None:
        warnings.append("Student total years of experience is missing.")
        return _scored("experience", 0)

    if minimum is not None and years < minimum:
        shortfall = minimum - years
        if shortfall <= EXPERIENCE_NEAR_MISS_YEARS and minimum > 0:
            score = BASE_WEIGHTS["experience"] * (years / minimum)
            reasons.append("Student experience is close to the requested minimum.")
            warnings.append("Student experience is slightly below the requested minimum.")
        else:
            score = 0
            warnings.append("Student experience is below the requested minimum.")
    else:
        score = BASE_WEIGHTS["experience"]
        if minimum is not None:
            reasons.append("Student meets or exceeds the minimum experience requirement.")
        else:
            reasons.append("Student experience satisfies the job requirement.")

    return _scored("experience", score)


def _major_score(student: dict[str, Any], job: dict[str, Any], reasons: list[str], warnings: list[str]) -> dict[str, Any]:
    target = normalize_major(job.get("required_major"))
    if not target:
        return _not_applicable("major", "Job does not specify a required major.")

    resume = student.get("resume") or {}
    candidates = [student.get("major")]

    for item in resume.get("education") or []:
        if isinstance(item, dict):
            candidates.extend([item.get("field_of_study"), item.get("major"), item.get("degree")])

    for item in student.get("education") or []:
        if isinstance(item, dict):
            candidates.extend([item.get("major"), item.get("degree")])

    normalized = [normalize_major(value) for value in candidates if clean_text(value)]
    if not normalized:
        warnings.append("Student major or education data is missing.")
        return _scored("major", 0)

    if target in normalized:
        reasons.append("Student major or education matches the job requirement.")
        return _scored("major", BASE_WEIGHTS["major"])

    target_tokens = tokenize(target)
    for candidate in normalized:
        candidate_tokens = tokenize(candidate)
        if target_tokens and candidate_tokens and target_tokens.issubset(candidate_tokens):
            reasons.append("Student education is closely related to the required major.")
            return _scored("major", BASE_WEIGHTS["major"] * 0.8)

    return _scored("major", 0)


def _projects_score(student: dict[str, Any], job: dict[str, Any], reasons: list[str], warnings: list[str]) -> dict[str, Any]:
    job_skills = normalize_skills(job.get("skills"))
    target_text = " ".join(
        str(job.get(key) or "")
        for key in ["title", "requirements", "description"]
    )
    target_tokens = tokenize(target_text) | set(job_skills)

    if not target_tokens:
        return _not_applicable("projects", "Job information is insufficient to evaluate projects.")

    projects = (student.get("resume") or {}).get("projects") or []
    if not projects:
        warnings.append("Student project data is missing.")
        return _scored("projects", 0)

    best = 0.0
    for project in projects:
        project_text = _entry_text(project)
        project_tokens = tokenize(project_text)
        skill_hits = sum(1 for skill in job_skills if skill in project_text.lower() or tokenize(skill).issubset(project_tokens))
        token_hits = len(target_tokens & project_tokens)
        score = min(1.0, (skill_hits * 0.35) + min(0.65, token_hits / max(len(target_tokens), 1)))
        best = max(best, score)

    if best > 0:
        reasons.append("Resume projects show evidence related to the target job.")

    return _scored("projects", BASE_WEIGHTS["projects"] * best)


def _preferences_score(student: dict[str, Any], job: dict[str, Any], reasons: list[str], warnings: list[str]) -> dict[str, Any]:
    score = 0.0
    applicable = 0.0

    if clean_text(job.get("location")):
        applicable += 5.0
        if clean_text(student.get("location")):
            if clean_text(student.get("location")) == clean_text(job.get("location")):
                score += 5.0
                reasons.append("Student location matches the job location.")
        else:
            warnings.append("Student location preference data is missing.")

    if clean_text(job.get("employment_type")):
        applicable += 5.0
        if clean_text(student.get("preferred_employment_type")):
            if clean_text(student.get("preferred_employment_type")) == clean_text(job.get("employment_type")):
                score += 5.0
                reasons.append("Student preferred employment type matches the job.")
        else:
            warnings.append("Student employment type preference is missing.")

    if applicable == 0:
        return _not_applicable("preferences", "Job does not specify location or employment type.")

    return {
        "score": round(score, 2),
        "max_weight": applicable,
        "base_weight": BASE_WEIGHTS["preferences"],
        "applicable": True,
        "status": "scored",
    }


def _entry_text(item: Any) -> str:
    if not isinstance(item, dict):
        return str(item or "")

    return " ".join(str(item.get(key) or "") for key in ["name", "title", "description", "technologies", "skills"])


def _number(value: Any) -> float | None:
    if value is None or value == "":
        return None

    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _scored(name: str, score: float) -> dict[str, Any]:
    return {
        "score": round(max(0.0, min(BASE_WEIGHTS[name], score)), 2),
        "max_weight": BASE_WEIGHTS[name],
        "applicable": True,
        "status": "scored",
    }


def _not_applicable(name: str, reason: str) -> dict[str, Any]:
    return {
        "score": 0,
        "max_weight": BASE_WEIGHTS[name],
        "applicable": False,
        "status": "criterion_not_applicable",
        "reason": reason,
    }


def _level(score: int) -> str:
    if score >= 90:
        return "Excellent Match"
    if score >= 75:
        return "Good Match"
    if score >= 60:
        return "Fair Match"
    return "Low Match"
