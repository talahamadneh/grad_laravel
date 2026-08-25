from app import JobMatchPayload, match
from matching.job_matcher import match_job


def base_payload():
    return {
        "student": {
            "major": "Computer Science",
            "location": "Amman",
            "preferred_employment_type": "Full-Time",
            "skills": [{"name": "PHP"}],
            "education": [{"degree": "BS Computer Science", "major": "Computer Science"}],
            "resume": {
                "total_years_experience": 1.5,
                "skills": [{"name": "Laravel"}, {"name": "PHP"}, {"name": "MySQL"}],
                "education": [{"degree": "BS Computer Science", "field_of_study": "Computer Science"}],
                "experience": [
                    {"title": "Developer Intern", "description": "Built Laravel REST API features."}
                ],
                "projects": [
                    {
                        "name": "Career Platform",
                        "description": "Laravel MySQL backend with REST APIs for job matching.",
                    }
                ],
            },
        },
        "job": {
            "title": "Backend Developer",
            "description": "Build Laravel APIs and MySQL features.",
            "requirements": "Laravel, PHP, MySQL, REST API development.",
            "skills": [{"name": "Laravel"}, {"name": "PHP"}, {"name": "MySQL"}],
            "required_major": "Computer Science",
            "min_experience_years": 1,
            "max_experience_years": 3,
            "location": "Amman",
            "employment_type": "Full-Time",
            "level": "Junior",
        },
    }


def test_perfect_skills_match():
    result = match_job(base_payload())
    assert result["breakdown"]["skills"]["score"] == 45
    assert set(result["matching_skills"]) >= {"laravel", "php", "mysql"}
    assert result["missing_skills"] == []


def test_partial_skills_match():
    payload = base_payload()
    payload["student"]["resume"]["skills"] = [{"name": "Laravel"}]
    payload["student"]["skills"] = []
    payload["student"]["resume"]["experience"] = []
    payload["student"]["resume"]["projects"] = []
    result = match_job(payload)
    assert 0 < result["breakdown"]["skills"]["score"] < 45
    assert "php" in result["missing_skills"]


def test_alias_normalization():
    payload = base_payload()
    payload["student"]["resume"]["skills"] = [{"name": "JS"}, {"name": "REST API"}]
    payload["student"]["skills"] = []
    payload["job"]["skills"] = [{"name": "JavaScript"}, {"name": "REST APIs"}]
    result = match_job(payload)
    assert result["breakdown"]["skills"]["score"] == 45
    assert result["missing_skills"] == []


def test_missing_student_skill_data():
    payload = base_payload()
    payload["student"]["resume"]["skills"] = []
    payload["student"]["skills"] = []
    payload["student"]["resume"]["experience"] = []
    payload["student"]["resume"]["projects"] = []
    result = match_job(payload)
    assert result["breakdown"]["skills"]["score"] == 0
    assert "Student skill data is missing." in result["warnings"]


def test_experience_inside_range():
    result = match_job(base_payload())
    assert result["breakdown"]["experience"]["score"] == 20


def test_experience_below_minimum():
    payload = base_payload()
    payload["student"]["resume"]["total_years_experience"] = 0.5
    payload["job"]["min_experience_years"] = 1
    result = match_job(payload)
    assert 0 < result["breakdown"]["experience"]["score"] < 20


def test_min_only_five_plus_experience():
    payload = base_payload()
    payload["student"]["resume"]["total_years_experience"] = 6
    payload["job"]["min_experience_years"] = 5
    payload["job"]["max_experience_years"] = None
    result = match_job(payload)
    assert result["breakdown"]["experience"]["score"] == 20


def test_job_experience_unspecified_redistributes():
    payload = base_payload()
    payload["job"]["min_experience_years"] = None
    payload["job"]["max_experience_years"] = None
    result = match_job(payload)
    assert result["breakdown"]["experience"]["applicable"] is False
    assert result["score"] == 100


def test_matching_major():
    result = match_job(base_payload())
    assert result["breakdown"]["major"]["score"] == 15


def test_missing_student_major_when_required():
    payload = base_payload()
    payload["student"]["major"] = None
    payload["student"]["education"] = []
    payload["student"]["resume"]["education"] = []
    result = match_job(payload)
    assert result["breakdown"]["major"]["score"] == 0
    assert "Student major or education data is missing." in result["warnings"]


def test_job_major_unspecified_redistributes():
    payload = base_payload()
    payload["job"]["required_major"] = None
    result = match_job(payload)
    assert result["breakdown"]["major"]["applicable"] is False
    assert result["score"] == 100


def test_relevant_project():
    result = match_job(base_payload())
    assert result["breakdown"]["projects"]["score"] > 0


def test_unrelated_project():
    payload = base_payload()
    payload["student"]["resume"]["projects"] = [
        {"name": "Art Portfolio", "description": "Paintings and photography gallery."}
    ]
    result = match_job(payload)
    assert result["breakdown"]["projects"]["score"] == 0


def test_location_match():
    result = match_job(base_payload())
    assert result["breakdown"]["preferences"]["score"] >= 5


def test_employment_type_match():
    result = match_job(base_payload())
    assert result["breakdown"]["preferences"]["score"] == 10


def test_missing_student_preference():
    payload = base_payload()
    payload["student"]["location"] = None
    payload["student"]["preferred_employment_type"] = None
    result = match_job(payload)
    assert result["breakdown"]["preferences"]["score"] == 0
    assert "Student location preference data is missing." in result["warnings"]
    assert "Student employment type preference is missing." in result["warnings"]


def test_dynamic_weight_redistribution():
    payload = base_payload()
    payload["job"]["required_major"] = None
    payload["job"]["min_experience_years"] = None
    payload["job"]["max_experience_years"] = None
    payload["job"]["location"] = None
    payload["job"]["employment_type"] = None
    result = match_job(payload)
    assert result["breakdown"]["major"]["applicable"] is False
    assert result["breakdown"]["experience"]["applicable"] is False
    assert result["breakdown"]["preferences"]["applicable"] is False
    assert result["score"] == 100


def test_score_always_zero_to_one_hundred():
    result = match_job(base_payload())
    assert 0 <= result["score"] <= 100

    emptyish = {"student": {"resume": {}}, "job": {"skills": [{"name": "Redis"}]}}
    result = match_job(emptyish)
    assert 0 <= result["score"] <= 100


def test_no_external_api_dependency(monkeypatch):
    def fail(*args, **kwargs):
        raise AssertionError("External network call attempted")

    monkeypatch.setattr("urllib.request.urlopen", fail)
    result = match_job(base_payload())
    assert result["score"] > 0


def test_match_job_endpoint():
    body = match(JobMatchPayload(**base_payload()))
    assert body["score"] == 100
    assert "breakdown" in body
