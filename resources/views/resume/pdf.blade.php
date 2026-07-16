<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $resume->full_name }} - Resume</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1e293b;
            font-size: 12px;
            margin: 0;
            padding: 0;
        }
        .header {
            background: #0f172a;
            color: #fff;
            padding: 30px 40px;
        }
        .header table { width: 100%; border-collapse: collapse; }
        .avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
        }
        .name { font-size: 24px; font-weight: bold; margin: 0 0 4px 0; }
        .title { font-size: 14px; color: #cbd5e1; margin: 0 0 12px 0; }
        .contact-line { font-size: 10.5px; color: #e2e8f0; }
        .contact-line span { margin-right: 16px; }
        .content { padding: 30px 40px; }
        .section { margin-bottom: 22px; }
        .section-title {
            font-size: 10.5px;
            font-weight: bold;
            color: #3b82f6;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 6px;
            margin-bottom: 12px;
        }
        .item { margin-bottom: 14px; }
        .item-title { font-size: 13px; font-weight: bold; color: #0f172a; }
        .item-sub { font-size: 11.5px; color: #64748b; }
        .item-date { font-size: 10px; color: #94a3b8; float: right; }
        .item-desc { font-size: 11.5px; color: #475569; line-height: 1.5; margin-top: 4px; }
        .chip {
            display: inline-block;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 4px 10px;
            margin: 0 6px 6px 0;
            font-size: 11px;
            color: #1e293b;
        }
        .clear { clear: both; }
    </style>
</head>
<body>

    <div class="header">
        <table>
            <tr>
                @if($avatar)
                <td style="width: 80px;">
                    <img src="{{ $avatar }}" class="avatar" />
                </td>
                @endif
                <td>
                    <p class="name">{{ $resume->full_name }}</p>
                    <p class="title">{{ $resume->professional_title }}</p>
                    <div class="contact-line">
                        @if($email)<span>{{ $email }}</span>@endif
                        @if($phone)<span>{{ $phone }}</span>@endif
                        @if($location)<span>{{ $location }}</span>@endif
                        @if($portfolio)<span>{{ $portfolio }}</span>@endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="content">

        @if($resume->summary)
        <div class="section">
            <div class="section-title">Summary</div>
            <div class="item-desc">{{ $resume->summary }}</div>
        </div>
        @endif

        @if(count($education))
        <div class="section">
            <div class="section-title">Education</div>
            @foreach($education as $e)
            <div class="item">
                <span class="item-date">{{ $e['start_date'] ?? '' }} - {{ $e['end_date'] ?? 'Present' }}</span>
                <div class="item-title">{{ $e['university'] ?? '' }}</div>
                <div class="item-sub">{{ $e['degree'] ?? '' }} {{ !empty($e['field_of_study']) ? 'in ' . $e['field_of_study'] : '' }}</div>
                <div class="clear"></div>
            </div>
            @endforeach
        </div>
        @endif

        @if(count($experience))
        <div class="section">
            <div class="section-title">Experience</div>
            @foreach($experience as $e)
            <div class="item">
                <span class="item-date">{{ $e['start_date'] ?? '' }} - {{ $e['end_date'] ?? 'Present' }}</span>
                <div class="item-title">{{ $e['title'] ?? '' }} @if(!empty($e['company'])) &middot; {{ $e['company'] }} @endif</div>
                <div class="item-desc">{{ $e['description'] ?? '' }}</div>
                <div class="clear"></div>
            </div>
            @endforeach
        </div>
        @endif

        @if(count($projects))
        <div class="section">
            <div class="section-title">Projects</div>
            @foreach($projects as $p)
            <div class="item">
                <div class="item-title">{{ $p['name'] ?? '' }}</div>
                @if(!empty($p['link']))<div class="item-sub">{{ $p['link'] }}</div>@endif
                <div class="item-desc">{{ $p['description'] ?? '' }}</div>
            </div>
            @endforeach
        </div>
        @endif

        @if(count($skills))
        <div class="section">
            <div class="section-title">Skills</div>
            @foreach($skills as $s)
                <span class="chip">{{ $s['name'] ?? '' }}</span>
            @endforeach
        </div>
        @endif

        @if(count($certificates))
        <div class="section">
            <div class="section-title">Certificates</div>
            @foreach($certificates as $c)
            <div class="item">
                <span class="item-date">{{ $c['year'] ?? '' }}</span>
                <div class="item-title">{{ $c['name'] ?? '' }} @if(!empty($c['issuer'])) &middot; {{ $c['issuer'] }} @endif</div>
                <div class="clear"></div>
            </div>
            @endforeach
        </div>
        @endif

        @if(count($languages))
        <div class="section">
            <div class="section-title">Languages</div>
            @foreach($languages as $l)
                <span class="chip">{{ $l['language'] ?? '' }} @if(!empty($l['level'])) &middot; {{ $l['level'] }} @endif</span>
            @endforeach
        </div>
        @endif

    </div>

</body>
</html>