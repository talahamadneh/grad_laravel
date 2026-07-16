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
    font-size: 12px;
}

.page {
    padding: 50px;
}

.header {
    width:100%;
    margin-bottom:35px;
}

.header-table {
    width:100%;
}

.left-header {
    width:70%;
}

.line {
    width:2px;
    height:50px;
    background:#333;
    margin-bottom:15px;
}

.first-name {
    margin:0;
    font-size:24px;
    font-weight:300;
    letter-spacing:8px;
    text-transform:uppercase;
    color:#444;
}

.last-name {
    margin:5px 0 0;
    font-size:42px;
    font-weight:bold;
    letter-spacing:10px;
    text-transform:uppercase;
    color:#222;
}

.title {
    margin-top:15px;
    font-size:11px;
    font-weight:bold;
    letter-spacing:3px;
    text-transform:uppercase;
    color:#666;
}


.avatar {
    width:130px;
    height:130px;
    border-radius:50%;
}


.columns {
    width:100%;
}

.left-column {
    width:35%;
    vertical-align:top;
    padding-right:40px;
}

.right-column {
    width:65%;
    vertical-align:top;
}


.section {
    margin-bottom:30px;
}


.section-title {

    font-size:15px;
    font-weight:bold;
    letter-spacing:4px;
    text-transform:uppercase;
    color:#222;
    margin-bottom:18px;
}


.contact-item {

    margin-bottom:12px;
    color:#444;
    font-size:12px;

}


.skill,
.language {

    margin-bottom:8px;
    color:#444;

}


.dot {

    width:4px;
    height:4px;
    background:#444;
    display:inline-block;
    border-radius:50%;
    margin-right:10px;

}


.profile {

    font-size:12px;
    color:#555;
    line-height:1.7;
    text-align:justify;
    text-transform:uppercase;
}


.timeline-item {

    margin-bottom:20px;

}


.timeline-title {

    font-size:13px;
    font-weight:bold;
    text-transform:uppercase;
}


.timeline-sub {

    color:#555;
    margin-top:5px;

}


.timeline-date {

    color:#777;
    font-size:11px;

}


.description {

    color:#555;
    line-height:1.6;
    margin-top:8px;

}


</style>

</head>


<body>


<div class="page">


<!-- HEADER -->

<table class="header-table">

<tr>

<td class="left-header">

<div class="line"></div>


<?php
$nameParts = explode(' ', trim($resume->full_name));

$first = $nameParts[0] ?? '';
$last = implode(' ', array_slice($nameParts,1));

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



<!-- BODY -->


<table class="columns">


<tr>


<td class="left-column">


@if($resume->location || $resume->phone || $resume->email)

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



</td>


</tr>

</table>


</div>


</body>
</html>