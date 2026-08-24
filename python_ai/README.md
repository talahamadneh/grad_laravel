# Local Python CV Analyzer

This service is the primary CV Review engine for the Laravel career platform. It does not call Groq, Gemini, OpenAI, or any external AI API.

## Architecture

Laravel reads the resume from the database, sends only the required CV fields to this local service, receives structured JSON, and stores the compatible fields in `resume_analysis`.

The Python service has no database credentials. This follows data minimization: only CV fields needed for analysis are sent.

## Requirements

- Python 3.11+
- FastAPI
- Uvicorn

Install:

```bash
cd python_ai
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

Run locally:

```bash
uvicorn app:app --host 127.0.0.1 --port 8001
```

## Endpoint

`POST /analyze-cv`

Example request:

```json
{
  "professional_title": "Software Developer",
  "summary": "Computer science student focused on Laravel, React, and API development.",
  "skills": ["PHP", "Laravel", "React.js", "MySQL"],
  "education": [{"degree": "BS Computer Science", "university": "Example University"}],
  "experience": [],
  "projects": [{"name": "Career Platform", "description": "Developed a Laravel and React platform with REST APIs."}],
  "certificates": [],
  "languages": [],
  "contact": {"email": "student@example.com", "github": "https://github.com/example"}
}
```

## Scoring Weights

- CV completeness: 20 points
- Professional summary quality: 15 points
- Skills quality: 20 points
- Experience and projects quality: 20 points
- Estimated ATS readiness: 15 points
- Consistency and quality: 10 points

The overall score is the sum of these categories. The ATS score is estimated locally from structure, missing sections, keyword coverage, long paragraphs, duplicate content, and unusual symbols. It is not a full simulation of commercial ATS products.

## Laravel Configuration

Set these in `.env`:

```env
CV_ANALYZER_URL=http://127.0.0.1:8001
CV_ANALYZER_TIMEOUT=8
CV_EXTERNAL_AI_ENABLED=false
AI_PROVIDER=groq
```

`CV_EXTERNAL_AI_ENABLED=false` keeps the CV Review fully local. When enabled, Laravel may use Groq only to enhance wording, not to replace the Python score.

## Privacy

The Python service should bind to `127.0.0.1` or a private network. Do not expose it publicly without authentication and network controls. Do not send passwords, tokens, unrelated users, or internal system data.

