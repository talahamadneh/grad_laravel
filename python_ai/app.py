from typing import Any

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from analyzer.cv_analyzer import analyze_cv


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

