from typing import Any

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from analyzer.cv_analyzer import analyze_cv
from matching.job_matcher import match_job


class CVPayload(BaseModel):
    professional_title: str | None = None
    summary: str | None = None
    skills: list[Any] = Field(default_factory=list)
    education: list[Any] = Field(default_factory=list)
    experience: list[Any] = Field(default_factory=list)
    projects: list[Any] = Field(default_factory=list)
    certificates: list[Any] = Field(default_factory=list)
    languages: list[Any] = Field(default_factory=list)
    contact: dict[str, Any] = Field(default_factory=dict)


class JobMatchPayload(BaseModel):
    student: dict[str, Any] = Field(default_factory=dict)
    job: dict[str, Any] = Field(default_factory=dict)


class JobBatchMatchPayload(BaseModel):
    student: dict[str, Any] = Field(default_factory=dict)
    jobs: list[dict[str, Any]] = Field(default_factory=list)


app = FastAPI(title="Local CV Analyzer", version="1.0.0")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/analyze-cv")
def analyze(payload: CVPayload) -> dict[str, Any]:
    cv = payload.model_dump()

    if not any([cv.get("summary"), cv.get("skills"), cv.get("education"), cv.get("experience"), cv.get("projects")]):
        raise HTTPException(status_code=422, detail="CV payload is too empty to analyze.")

    return analyze_cv(cv)


@app.post("/match-job")
def match(payload: JobMatchPayload) -> dict[str, Any]:
    data = payload.model_dump()

    if not data.get("student") or not data.get("job"):
        raise HTTPException(status_code=422, detail="Student and job payloads are required.")

    return match_job(data)


@app.post("/match-jobs")
def match_batch(payload: JobBatchMatchPayload) -> dict[str, Any]:
    data = payload.model_dump()
    student = data.get("student")
    jobs = data.get("jobs") or []

    if not student or not jobs:
        raise HTTPException(status_code=422, detail="Student and jobs payloads are required.")

    results = []
    for job in jobs:
        result = match_job({"student": student, "job": job})
        results.append({
            "job_id": job.get("id"),
            **result,
        })

    return {"results": results}
