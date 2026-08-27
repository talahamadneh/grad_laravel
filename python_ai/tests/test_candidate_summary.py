from app import CandidateSummaryPayload, candidate_summary
from candidate_summary import generate_candidate_summary


def base_payload():
    return {
        "job_title": "Junior Backend Developer",
        "match_percentage": 82,
        "matching_skills": ["Laravel", "PHP", "MySQL"],
        "missing_skills": ["REST APIs"],
        "candidate_skills": ["Laravel", "PHP", "MySQL"],
        "professional_title": "Junior Laravel Developer",
        "major": "Computer Science",
        "relevant_experience": [
            {"position": "Backend Intern", "company": "Acme"}
        ],
        "relevant_projects": [
            {"name": "Career Platform", "description": "Laravel and MySQL platform"}
        ],
    }


def test_high_match_with_no_missing_skills():
    payload = base_payload()
    payload["match_percentage"] = 92
    payload["missing_skills"] = []

    result = generate_candidate_summary(payload)

    assert "strong 92% match" in result["summary"]
    assert "Laravel, PHP, and MySQL" in result["summary"]
    assert "appears suitable to proceed" in result["summary"]


def test_medium_match_with_missing_skills():
    result = generate_candidate_summary(base_payload())

    assert "strong 82% match" in result["summary"]
    assert "REST APIs should be verified" in result["summary"]
    assert "may be suitable to proceed" in result["summary"]


def test_missing_skill_capitalization_is_preserved():
    payload = base_payload()
    payload["missing_skills"] = ["REST API"]

    result = generate_candidate_summary(payload)

    assert "REST API should be verified" in result["summary"]


def test_low_match_is_cautious_without_rejecting():
    payload = base_payload()
    payload["match_percentage"] = 32

    result = generate_candidate_summary(payload)

    assert "developing 32% match" in result["summary"]
    assert "reviewed cautiously" in result["summary"]
    assert "reject" not in result["summary"].lower()


def test_missing_experience_is_handled_gracefully():
    payload = base_payload()
    payload["relevant_experience"] = []

    result = generate_candidate_summary(payload)

    assert "project work on Career Platform" in result["summary"]
    assert "experience in" not in result["summary"]


def test_missing_projects_is_handled_gracefully():
    payload = base_payload()
    payload["relevant_projects"] = []

    result = generate_candidate_summary(payload)

    assert "experience in Backend Intern" in result["summary"]
    assert "project work on" not in result["summary"]


def test_no_matching_skills():
    payload = base_payload()
    payload["matching_skills"] = []

    result = generate_candidate_summary(payload)

    assert "No direct matching technical skills" in result["summary"]


def test_no_missing_skills():
    payload = base_payload()
    payload["missing_skills"] = []

    result = generate_candidate_summary(payload)

    assert "missing or unclear requirements" not in result["summary"]


def test_same_input_produces_identical_summary():
    payload = base_payload()

    first = generate_candidate_summary(payload)
    second = generate_candidate_summary(payload)

    assert first == second


def test_candidate_summary_endpoint():
    result = candidate_summary(CandidateSummaryPayload(**base_payload()))

    assert "summary" in result
    assert "Junior Backend Developer" in result["summary"]
