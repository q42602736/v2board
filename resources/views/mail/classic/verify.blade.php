<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>邮箱验证码</title>
    <style type="text/css">
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        img {
            max-width: 100%;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            -webkit-text-size-adjust: none;
            width: 100% !important;
            height: 100%;
            line-height: 1.6em;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
        }

        .email-wrapper {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }

        @media only screen and (max-width: 640px) {
            body {
                padding: 10px !important;
            }

            h1 {
                font-weight: 700 !important;
                margin: 20px 0 5px !important;
                font-size: 22px !important;
            }

            h2 {
                font-weight: 600 !important;
                margin: 20px 0 5px !important;
                font-size: 18px !important;
            }

            h3 {
                font-weight: 600 !important;
                margin: 20px 0 5px !important;
                font-size: 16px !important;
            }

            .container {
                padding: 0 !important;
                width: 100% !important;
            }

            .content {
                padding: 0 !important;
            }

            .content-wrap {
                padding: 20px !important;
            }

            .verification-code {
                font-size: 36px !important;
                letter-spacing: 4px !important;
                padding: 15px !important;
            }

            .code-container {
                padding: 20px !important;
            }
        }
    </style>
</head>

<body itemscope itemtype="http://schema.org/EmailMessage">
    <div class="email-wrapper">
        <table class="body-wrap" width="100%" cellpadding="0" cellspacing="0" style="width: 100%; margin: 0;">
            <tr>
                <td style="vertical-align: top;" valign="top"></td>
                <td class="container" width="600" style="vertical-align: top; display: block !important; max-width: 600px !important; clear: both !important; margin: 0 auto;" valign="top">
                    <div class="content" style="max-width: 600px; display: block; margin: 0 auto; padding: 0;">
                        <!-- 主邮件容器 -->
                        <table class="main" width="100%" cellpadding="0" cellspacing="0" style="border-radius: 20px; background-color: #fff; margin: 0; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1); overflow: hidden;" bgcolor="#fff">
                            <!-- 头部 -->
                            <tr>
                                <td class="header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-align: center; padding: 40px 30px;" align="center" valign="top">
                                    <h1 style="font-size: 28px; font-weight: 700; margin: 0 0 8px 0; letter-spacing: -0.5px;">{{$name}}</h1>
                                    <p style="font-size: 16px; opacity: 0.9; font-weight: 400; margin: 0;">安全验证服务</p>
                                </td>
                            </tr>
                            <!-- 内容区域 -->
                            <tr>
                                <td class="content-wrap" style="padding: 50px 40px; text-align: center;" valign="top" align="center">
                                    <!-- 问候语 -->
                                    <div style="font-size: 18px; color: #374151; margin-bottom: 30px; font-weight: 500;">
                                        尊敬的用户，您好！👋
                                    </div>

                                    <!-- 验证码区域 -->
                                    <div class="code-container" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 16px; padding: 40px 30px; margin: 30px 0; border: 2px solid #e2e8f0;">
                                        <div style="font-size: 16px; color: #64748b; margin-bottom: 20px; font-weight: 500;">
                                            您的邮箱验证码
                                        </div>
                                        <div class="verification-code" style="font-size: 48px; font-weight: 700; color: #1e293b; letter-spacing: 8px; margin: 20px 0; font-family: 'Courier New', monospace; background: white; padding: 20px; border-radius: 12px; border: 3px dashed #667eea; display: inline-block; min-width: 280px;">
                                            {{$code}}
                                        </div>
                                    </div>

                                    <!-- 时间提醒 -->
                                    <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 15px; margin: 25px 0; color: #92400e; font-size: 14px; font-weight: 500;">
                                        ⏰ 验证码有效期为 {{$expire_minutes}} 分钟，请尽快完成验证
                                    </div>

                                    <!-- 安全提醒 -->
                                    <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 15px; margin: 25px 0; color: #991b1b; font-size: 14px; line-height: 1.5;">
                                        🔒 如果您未申请此验证码，请忽略此邮件。为了您的账户安全，请勿将验证码告知他人。
                                    </div>

                                    <!-- 按钮 -->
                                    <a href="{{$url}}" style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 16px 32px; border-radius: 12px; font-weight: 600; font-size: 16px; margin: 20px 0; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);">
                                        返回 {{$name}}
                                    </a>
                                </td>
                            </tr>
                            <!-- 底部 -->
                            <tr>
                                <td class="footer" style="background: #f8fafc; padding: 30px; text-align: center; border-top: 1px solid #e2e8f0;" align="center" valign="top">
                                    <div style="margin-bottom: 20px;">
                                        <a href="{{$url}}/#/subscribe" style="color: #667eea; text-decoration: none; margin: 0 15px; font-weight: 500; font-size: 14px;">我的订阅</a>
                                        <a href="{{$url}}/#/knowledge" style="color: #667eea; text-decoration: none; margin: 0 15px; font-weight: 500; font-size: 14px;">使用教程</a>
                                        <a href="{{$url}}/#/profile" style="color: #667eea; text-decoration: none; margin: 0 15px; font-weight: 500; font-size: 14px;">个人中心</a>
                                    </div>
                                    <div style="color: #64748b; font-size: 12px; margin-top: 15px;">
                                        © {{$name}}. All Rights Reserved. | 本邮件由系统自动发送，请勿回复
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </td>
                <td style="vertical-align: top;" valign="top"></td>
            </tr>
        </table>
    </div>
</body>

</html>
