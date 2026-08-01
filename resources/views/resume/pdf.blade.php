<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $resume->full_name }} - Resume</title>

<style>
* {
    box-sizing: border-box;
}

body {
    font-family: DejaVu Sans, sans-serif;
    color: #222;
    margin: 0;
    padding: 0;
    font-size: 10px;
}

.page {
    padding: 25px;
}

.header {
    width: 100%;
    margin-bottom: 20px;
}

.header-table {
    width: 100%;
}

.left-header {
    width: 70%;
}

.line {
    width: 2px;
    height: 40px;
    background: #333;
    margin-bottom: 10px;
}

.first-name {
    margin: 0;
    font-size: 20px;
    font-weight: 300;
    letter-spacing: 6px;
    text-transform: uppercase;
    color: #444;
}

.last-name {
    margin: 4px 0 0;
    font-size: 32px;
    font-weight: bold;
    letter-spacing: 8px;
    text-transform: uppercase;
    color: #222;
}

.title {
    margin-top: 10px;
    font-size: 10px;
    font-weight: bold;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #666;
}

.avatar {
    width: 90px;
    height: 90px;
    border-radius: 50%;
}

.columns {
    width: 100%;
}

.left-column {
    width: 35%;
    vertical-align: top;
    padding-right: 25px;
}

.right-column {
    width: 65%;
    vertical-align: top;
}

.section {
    margin-bottom: 15px;
}

.section-title {
    font-size: 13px;
    font-weight: bold;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: #222;
    margin-bottom: 10px;
}

.contact-item {
    margin-bottom: 8px;
    color: #444;
    font-size: 10px;
}

.skill,
.language {
    margin-bottom: 6px;
    color: #444;
}

.dot {
    width: 4px;
    height: 4px;
    background: #444;
    display: inline-block;
    border-radius: 50%;
    margin-right: 8px;
}

.profile {
    font-size: 10px;
    color: #555;
    line-height: 1.5;
    text-align: justify;
}

.timeline-item {
    margin-bottom: 12px;
}

.timeline-title {
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.timeline-sub {
    color: #555;
    margin-top: 3px;
}

.timeline-date {
    color: #777;
    font-size: 9px;
}

.description {
    color: #555;
    line-height: 1.4;
    margin-top: 5px;
}
</style>
</head>

<body>

<div class="page">

<table class="header-table">
<tr>
<td class="left-header">

<div class="line"></div>

<?php
$nameParts = explode(' ', trim($resume->full_name));
$first = $nameParts[0] ?? '';
$last = implode(' ', array_slice($nameParts, 1));
?>

<h1 class="first-name">
{{ $first }}
</h1>

<h1 class="last-name">
{{ $last }}
</h1>

<p class="title">
{{ $resume->professional_title }}
</p>

</td>

<td align="right">
@if($avatar)
<img class="avatar" src="{{ $avatar }}">
@endif
</td>
</tr>
</table>

<table class="columns">
<tr>

<td class="left-column">

@if($resume->location || $resume->phone || $resume->email || $linkedin || $github || $portfolio)
<div class="section">

<h3 class="section-title">
CONTACT
</h3>

@if($resume->location)
<div class="contact-item">
📍 {{ $resume->location }}
</div>
@endif

@if($resume->phone)
<div class="contact-item">
☎ {{ $resume->phone }}
</div>
@endif

@if($resume->email)
<div class="contact-item">
✉ {{ $resume->email }}
</div>
@endif

@if($linkedin)
<div class="contact-item">
LinkedIn: {{ $linkedin }}
</div>
@endif

@if($github)
<div class="contact-item">
GitHub: {{ $github }}
</div>
@endif

@if($portfolio)
<div class="contact-item">
Portfolio: {{ $portfolio }}
</div>
@endif

</div>
@endif

@if(count($skills))
<div class="section">

<h3 class="section-title">
SKILLS
</h3>

@foreach($skills as $s)
<div class="skill">
<span class="dot"></span>
{{ is_array($s) ? ($s['name'] ?? '') : $s }}
</div>
@endforeach

</div>
@endif

@if(count($education))
<div class="section">

<h3 class="section-title">
EDUCATION
</h3>

@foreach($education as $e)
<div class="timeline-item">

<div class="timeline-title">
{{ $e['degree'] ?? '' }}
@if(!empty($e['field_of_study']))
IN {{ $e['field_of_study'] }}
@endif
</div>

<div class="timeline-sub">
{{ $e['university'] ?? $e['institution'] ?? '' }}
</div>

<div class="timeline-date">
Graduated:
{{ $e['end_date'] ?? 'Present' }}
</div>

@if($gpa)
<div class="timeline-date">
GPA: {{ $gpa }}
</div>
@endif

</div>
@endforeach

</div>
@endif

@if(count($languages))
<div class="section">

<h3 class="section-title">
LANGUAGES
</h3>

@foreach($languages as $l)
<div class="language">
<span class="dot"></span>
<b>{{ $l['language'] ?? '' }}</b>
@if(!empty($l['level']))
({{ $l['level'] }})
@endif
</div>
@endforeach

</div>
@endif

@if(count($certificates))
<div class="section">

<h3 class="section-title">
CERTIFICATES
</h3>

@foreach($certificates as $c)
<div class="timeline-item">

<div class="timeline-title">
{{ $c['name'] ?? '' }}
</div>

<div class="timeline-sub">
{{ $c['issuer'] ?? '' }}
</div>

<div class="timeline-date">
{{ $c['year'] ?? '' }}
</div>

</div>
@endforeach

</div>
@endif

</td>

<td class="right-column">

@if($resume->summary)
<div class="section">

<h3 class="section-title">
PROFILE
</h3>

<p class="profile">
{{ $resume->summary }}
</p>

</div>
@endif

@if(count($experience))
<div class="section">

<h3 class="section-title">
EXPERIENCE
</h3>

@foreach($experience as $e)
<div class="timeline-item">

<div class="timeline-title">
{{ $e['title'] ?? $e['position'] ?? '' }}
</div>

<div class="timeline-date">
{{ $e['start_date'] ?? '' }}
-
{{ $e['end_date'] ?? 'Present' }}
</div>

<div class="timeline-sub">
{{ $e['company'] ?? '' }}
</div>

<div class="description">
{{ $e['description'] ?? '' }}
</div>

</div>
@endforeach

</div>
@endif

@if(count($projects))
<div class="section">

<h3 class="section-title">
PROJECTS
</h3>

@foreach($projects as $p)
<div class="timeline-item">

<div class="timeline-title">
{{ $p['name'] ?? '' }}
</div>

<div class="description">
{{ $p['description'] ?? '' }}
</div>

</div>
@endforeach

</div>
@endif

</td>

</tr>
</table>

</div>

</body>
</html>