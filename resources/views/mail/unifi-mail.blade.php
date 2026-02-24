@php
  $bgColor     = $data['bg_color']     ?? '#ffffff';
  $borderColor = $data['border_color'] ?? '#e5e7eb';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UniFi Notification</title>

<style>
  body {
    margin: 0;
    padding: 0;
    background-color: #f1f5f9;
    font-family: Arial, 'Segoe UI', sans-serif;
    color: #1f2937;
  }

  table {
    border-collapse: collapse;
    width: 100%;
  }

  .container {
    max-width: 600px;
    margin: 16px auto;
    background-color: #ffffff;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    overflow: hidden;
  }

  .header {
    padding: 12px 16px;
    text-align: center;
  }

  .header img {
    max-width: 160px;
    margin-bottom: 6px;
  }

  .site-title {
    font-size: 15px;
    font-weight: 600;
  }

  .content {
    padding: 12px 20px;
    text-align: center;
  }

  .alert-box {
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 12px;
  }

  .alert-title {
    font-size: 17px;
    font-weight: 600;
    margin-bottom: 4px;
  }

  .alert-desc {
    font-size: 13px;
    color: #374151;
    line-height: 1.4;
  }

  .info {
    margin-top: 10px;
    font-size: 13px;
    text-align: left;
  }

  .info-row {
    padding: 4px 0;
  }

  .label {
    color: #6b7280;
    width: 110px;
    display: inline-block;
  }

  .value {
    font-weight: 500;
  }

  .btn {
    display: inline-block;
    margin-top: 16px;
    padding: 10px 20px;
    background-color: #2563eb;
    color: #ffffff !important;
    border-radius: 6px;
    font-size: 13px;
    text-decoration: none;
  }

  .footer {
    background-color: #f9fafb;
    text-align: center;
    font-size: 11px;
    color: #6b7280;
    padding: 10px;
    line-height: 1.4;
  }
</style>
</head>

<body>
<table role="presentation">
<tr>
<td align="center">

<table class="container" role="presentation">

  <!-- HEADER -->
  <tr>
    <td class="header">
      @if(!empty($data['image']))
        <img src="{{ $data['image'] }}" alt="UniFi Notification">
      @endif

      <div class="site-title">
        {{ $data['site_name'] }} – {{ $data['device_name'] }}
      </div>
    </td>
  </tr>

  <!-- CONTENT -->
  <tr>
    <td class="content">

      <!-- ALERT -->
      <div class="alert-box" style="background-color: {{ $bgColor }}; border: 1px solid {{ $borderColor }};">
        <div class="alert-title">
          {{ $data['icon'] }} {{ $data['title'] }}
        </div>
        <div class="alert-desc">
          {{ $data['description'] }}
        </div>
      </div>

      <!-- INFO -->
      <div class="info">
        <div class="info-row">
          <strong><span class="label">Device</span></strong>
          <span class="value">{{ $data['device_name'] }}</span>
        </div>

        <div class="info-row">
          <strong><span class="label">Site</span></strong>
          <span class="value">{{ $data['site_name'] }}</span>
        </div>

        @if(!empty($data['detail']))
          @foreach($data['detail'] as $label => $value)
          <div class="info-row">
            <span class="label">{{ $label }}</span>
            <span class="value">{{ $value }}</span>
          </div>
          @endforeach
        @endif

        <div class="info-row">
          <strong><span class="label">Time</span></strong>
          <span class="value">{{ $data['time'] }}</span>
        </div>
      </div>

      @if(!empty($data['dashboard_url']))
      <a href="{{ $data['dashboard_url'] }}" class="btn" target="_blank">
        Go To Dashboard
      </a>
      @endif

    </td>
  </tr>

  <!-- FOOTER -->
  <tr>
    <td class="footer">
      Email ini dikirim otomatis oleh sistem monitoring jaringan.<br>
      © {{ now()->year }} UKPBJ Kabupaten Sidoarjo
    </td>
  </tr>

</table>

</td>
</tr>
</table>
</body>
</html>