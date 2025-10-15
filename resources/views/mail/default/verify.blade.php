<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>邮箱验证码</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .header p {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 400;
        }

        .content {
            padding: 50px 40px;
            text-align: center;
        }

        .greeting {
            font-size: 18px;
            color: #374151;
            margin-bottom: 30px;
            font-weight: 500;
        }

        .verification-section {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 16px;
            padding: 40px 30px;
            margin: 30px 0;
            border: 2px solid #e2e8f0;
        }

        .verification-label {
            font-size: 16px;
            color: #64748b;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .verification-code {
            font-size: 48px;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: 8px;
            margin: 20px 0;
            font-family: 'Courier New', monospace;
            background: white;
            padding: 20px;
            border-radius: 12px;
            border: 3px dashed #667eea;
            display: inline-block;
            min-width: 280px;
        }

        .timer-info {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 15px;
            margin: 25px 0;
            color: #92400e;
            font-size: 14px;
            font-weight: 500;
        }

        .timer-info::before {
            content: "⏰ ";
            font-size: 16px;
        }

        .security-notice {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            padding: 15px;
            margin: 25px 0;
            color: #991b1b;
            font-size: 14px;
            line-height: 1.5;
        }

        .security-notice::before {
            content: "🔒 ";
            font-size: 16px;
        }

        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .cta-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.6);
        }

        .footer {
            background: #f8fafc;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }

        .footer-links {
            margin-bottom: 20px;
        }

        .footer-links a {
            color: #667eea;
            text-decoration: none;
            margin: 0 15px;
            font-weight: 500;
            font-size: 14px;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        .copyright {
            color: #64748b;
            font-size: 12px;
            margin-top: 15px;
        }

        @media (max-width: 640px) {
            body {
                padding: 10px;
            }

            .content {
                padding: 30px 20px;
            }

            .header {
                padding: 30px 20px;
            }

            .header h1 {
                font-size: 24px;
            }

            .verification-code {
                font-size: 36px;
                letter-spacing: 4px;
                min-width: 240px;
            }

            .verification-section {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>{{$name}}</h1>
            <p>安全验证服务</p>
        </div>

        <div class="content">
            <div class="greeting">
                尊敬的用户，您好！👋
            </div>

            <div class="verification-section">
                <div class="verification-label">
                    您的邮箱验证码
                </div>
                <div class="verification-code">
                    {{$code}}
                </div>
            </div>

            <div class="timer-info">
                验证码有效期为 {{$expire_minutes}} 分钟，请尽快完成验证
            </div>

            <div class="security-notice">
                如果您未申请此验证码，请忽略此邮件。为了您的账户安全，请勿将验证码告知他人。
            </div>

            <a href="{{$url}}" class="cta-button">
                返回 {{$name}}
            </a>
        </div>

        <div class="footer">
            <div class="footer-links">
                <a href="{{$url}}/#/subscribe">我的订阅</a>
                <a href="{{$url}}/#/knowledge">使用教程</a>
                <a href="{{$url}}/#/profile">个人中心</a>
            </div>
            <div class="copyright">
                © {{$name}}. All Rights Reserved. | 本邮件由系统自动发送，请勿回复
            </div>
        </div>
    </div>
</body>
</html>
