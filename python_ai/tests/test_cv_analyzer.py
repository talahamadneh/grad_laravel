from analyzer.cv_analyzer import analyze_cv
from analyzer.text_utils import text_has_measurable_impact


def strong_cv():
    return {
        "professional_title": "Software Developer",
        "summary": "Computer science student focused on backend and full stack web development using Laravel, React, REST APIs, MySQL, and cloud deployment for junior software roles.",
        "skills": ["PHP", "Laravel", "React.js", "JavaScript", "MySQL", "Git", "Docker", "REST API"],
        "education": [{"degree": "BS Computer Science", "university": "Example University", "start_date": "2022", "end_date": "2026"}],
        "experience": [
            {
                "title": "Web Development Intern",
                "company": "Local Tech",
                "start_date": "2025-01",
                "end_date": "2025-04",
                "description": "Developed Laravel API features, optimized MySQL queries, and reduced dashboard load time by 30%.",
            }
        ],
        "projects": [
            {
                "name": "Career Platform",
                "start_date": "2025",
                "end_date": "2026",
                "description": "Built a Laravel and React career platform used by 50 students with authentication, REST APIs, and candidate matching.",
            }
        ],
        "certificates": [{"name": "Laravel Basics", "issuer": "Online Course", "year": "2025"}],
        "languages": [{"language": "English", "level": "Intermediate"}],
        "contact": {"email": "student@example.com", "github": "https://github.com/example", "linkedin": "https://linkedin.com/in/example"},
    }


def average_cv():
    return {
        "professional_title": "Web Developer",
        "summary": "Web developer with experience building websites using HTML, CSS, JavaScript, PHP, and databases.",
        "skills": ["HTML", "CSS", "JavaScript", "PHP", "Communication"],
        "education": [{"degree": "Diploma", "university": "Example College", "start_date": "2023", "end_date": "2025"}],
        "experience": [],
        "projects": [{"name": "Portfolio", "description": "Built a portfolio website using HTML, CSS, and JavaScript."}],
        "contact": {"email": "student@example.com"},
    }


def manual_average_cv():
    return {
        "professional_title": "Junior Web Developer",
        "summary": "Computer Engineering student interested in web development. I have experience working on university projects using PHP and MySQL and I am looking for opportunities to improve my software development skills.",
        "skills": ["PHP", "MySQL", "HTML", "CSS", "Communication", "Teamwork"],
        "education": [
            {
                "degree": "Computer Engineering",
                "institution": "An-Najah National University",
                "start_date": "2022-09",
                "end_date": "2027-01",
            }
        ],
        "experience": [],
        "projects": [
            {
                "title": "University Web Project",
                "description": "Developed a web application using PHP and MySQL for a university course.",
            }
        ],
        "certifications": [],
        "contact": {"email": "student@example.com", "phone": "", "linkedin": "", "github": ""},
    }


def weak_cv():
    return {
        "professional_title": "",
        "summary": "Hard working team player.",
        "skills": ["Communication", "Teamwork"],
        "education": [],
        "experience": [],
        "projects": [],
        "contact": {},
    }


def test_strong_average_and_weak_scores_are_ordered():
    strong = analyze_cv(strong_cv())
    average = analyze_cv(average_cv())
    weak = analyze_cv(weak_cv())

    assert strong["overall_score"] > average["overall_score"] > weak["overall_score"]
    assert strong["ats_score"] > average["ats_score"] > weak["ats_score"]
    assert strong["ats_score"] <= 95


def test_manual_average_cv_is_not_overrated():
    strong = analyze_cv(strong_cv())
    average = analyze_cv(manual_average_cv())
    weak = analyze_cv(weak_cv())

    assert weak["overall_score"] < average["overall_score"] < strong["overall_score"]
    assert average["level"] != "Excellent"
    assert average["ats_score"] <= strong["ats_score"] - 10
    assert average["section_scores"]["experience_projects"] <= 10
    assert average["overall_score"] >= 60


def test_five_relevant_technical_skills_outperform_many_generic_skills():
    technical = analyze_cv({**average_cv(), "skills": ["PHP", "Laravel", "React", "MySQL", "Git"]})
    generic = analyze_cv(
        {
            **average_cv(),
            "skills": [
                "Communication",
                "Teamwork",
                "Leadership",
                "Problem Solving",
                "Hard Working",
                "Microsoft Office",
                "Computer",
                "Creative",
                "Flexible",
                "Punctual",
                "Time Management",
                "Fast Learner",
                "Office",
                "Word",
                "Critical Thinking",
                "Communication",
            ],
        }
    )

    assert technical["section_scores"]["skills"] > generic["section_scores"]["skills"]
    assert "Skills section relies too much on generic skills." in generic["weaknesses"]


def test_duplicate_skills_create_feedback():
    result = analyze_cv({**average_cv(), "skills": ["React.js", "React", "Laravel", "PHP"]})

    assert "Duplicate skills were detected." in result["weaknesses"]


def test_keyword_stuffed_summary_is_penalized():
    stuffed = analyze_cv({**average_cv(), "summary": " ".join(["Laravel React API database software"] * 30)})
    clear = analyze_cv(average_cv())

    assert stuffed["section_scores"]["summary"] <= clear["section_scores"]["summary"]
    assert "Summary may be keyword-stuffed or too long." in stuffed["weaknesses"]


def test_measurable_impact_detection_distinguishes_results_from_context_numbers():
    assert text_has_measurable_impact("Improved performance by 30%.")
    assert text_has_measurable_impact("Reduced processing time from 10 minutes to 3 minutes.")
    assert text_has_measurable_impact("Built a system used by 50 students.")
    assert not text_has_measurable_impact("Worked for 2 months.")
    assert not text_has_measurable_impact("Used 3 programming languages.")
    assert not text_has_measurable_impact("Project completed in 2025.")
    assert not text_has_measurable_impact("Worked with a team of 4.")


def test_two_strong_projects_score_better_than_five_weak_projects():
    strong_projects = analyze_cv(
        {
            **average_cv(),
            "projects": [
                {
                    "name": "Analytics Dashboard",
                    "description": "Designed and built a React dashboard with REST API integration and improved report loading by 40%.",
                },
                {
                    "name": "Student Portal",
                    "description": "Implemented Laravel authentication, optimized MySQL queries, and supported 200 users during testing.",
                },
            ],
        }
    )
    weak_projects = analyze_cv(
        {
            **average_cv(),
            "projects": [
                {"name": "One", "description": "Website."},
                {"name": "Two", "description": "App."},
                {"name": "Three", "description": "System."},
                {"name": "Four", "description": "Dashboard."},
                {"name": "Five", "description": "API."},
            ],
        }
    )

    assert strong_projects["section_scores"]["experience_projects"] > weak_projects["section_scores"]["experience_projects"]


def test_invalid_date_range_reduces_consistency():
    result = analyze_cv({**average_cv(), "education": [{"degree": "BS Computer Science", "start_date": "2026", "end_date": "2022"}]})

    assert result["section_scores"]["consistency"] < 10
    assert "Some dates are missing, invalid, or inconsistent." in result["weaknesses"]


def test_duplicate_project_entries_are_penalized():
    duplicate = {
        "name": "Career Platform",
        "description": "Built a Laravel and React career platform with authentication and REST APIs.",
    }
    result = analyze_cv({**average_cv(), "projects": [duplicate, duplicate]})

    assert result["section_scores"]["consistency"] < 10
    assert "Duplicate CV entries were detected." in result["weaknesses"]
