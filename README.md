# Laybot 开源项目库

# LayBot 母仓 + 子目录多 SDK 的完整发布 & 迭代手册
（以 laybot-source ➜ laybot-php-sdk ➜ GitHub/Packagist 为示例， 其它语言 Node/Python 等同）

────────────────────────────────────  
目录
1. 总体结构
2. 首次创建子目录并首发到 GitHub / Packagist
3. 日常开发 & 发新版本
4. Packagist 自动同步设置
5. composer install / vendor / .gitignore 的正确姿势
6. 多语言 SDK 的横向复用模板
7. 常用脚本与快捷命令

================================================================
1. 母仓目录与远端约定
----------------------------------------------------------------  

```
laybot-source/              # 私有 GitLab 母仓（origin）
│
├─ laybot-php-sdk/          # ① PHP SDK（子目录）
│   ├─ composer.json        # name = "laybot/ai-sdk"
│   └─ src/…
│
├─ laybot-python-sdk/       # ② Python SDK（同理）
└─ ...
```

远端命名

```
origin            http://git.laybot.cn/laybot/laybot-source.git   # 私有
php-sdk (推)      git@github.com:laybot/ai-sdk-php.git            # 公共
py-sdk  (推)      git@github.com:laybot/ai-sdk-python.git         # 公共
```

SSH：  
– 单账号 → 用默认 `git@github.com:` + `id_ed25519`  
– 多账号 → 多把私钥 + `Host github.com-personal` / `github.com-work` 区分

================================================================
2. 子目录首次上线（以 PHP 为例）
----------------------------------------------------------------  

① 在 laybot-php-sdk 里 `composer init` 并完善 `composer.json`

```json
{
  "name": "laybot/ai-sdk",
  "description": "LayBot official PHP SDK",
  "type": "library",
  "license": "MIT",
  "autoload": { "psr-4": { "LayBot\\": "src/" } },
  "require": {
    "php": ">=8.0",
    "guzzlehttp/guzzle": "^7.9"
  }
}
```

② 不要提交 vendor / composer.lock  
在 laybot-source 根或子目录写 `.gitignore`：

```
/vendor
composer.lock
```

③ 母仓提交

```bash
git add laybot-php-sdk
git commit -m "feat: add php sdk"
```

④ 拆子树并推 GitHub

```bash
git subtree split --prefix=laybot-php-sdk -b php-split
git push -u php-sdk php-split:main         # 覆盖空仓
git tag v0.1.0 php-split
git push php-sdk v0.1.0
```

⑤ Packagist 首次提交  
复制 Webhook URL → GitHub Settings → Webhooks → push event

至此：  
`composer require laybot/ai-sdk:^0.1` 能装到 `vendor/laybot/ai-sdk/src`.

================================================================
3. 日常迭代 & 版本升级
----------------------------------------------------------------  

```bash
# 1) 修改 & 测试
cd laybot-source
(code…)

# 2) 安装依赖（仅本机 / CI）
cd laybot-php-sdk
composer install        # 会生成 vendor/ 但未提交

# 3) 回母仓根提交
cd ..
git add laybot-php-sdk
git commit -m "fix: xxx"

# 4) 拆分 & 推送
git subtree split --prefix=laybot-php-sdk -b php-split
git push -f php-sdk php-split:main

# 5) 打新版本
git tag v0.2.0 php-split
git push php-sdk v0.2.0      # Packagist Webhook 自动更新
```

守则  
• 永远 **新版本号**，不要 `-f` 覆盖已发 tag  
• 母仓 main 不推 GitHub；只推 php-split → main  
• 出错可在母仓重新 split，再 `git push -f …`

================================================================
4. Packagist 自动同步一次到位
----------------------------------------------------------------  

1. Packagist 包页面 → Settings → 复制 “GitHub Hook URL”
2. GitHub 仓库 Settings → Webhooks → Add  
   – Payload URL：粘贴  
   – Content type：application/json  
   – 只勾 push
3. 保存后 Ping = ✓  
   以后 `git push` + `git push tag` ≤30s 内版本就出现。

================================================================
5. 正确使用 composer install & IDE 提示
----------------------------------------------------------------  

场景：在 laybot-source 项目里希望 IDE 对 PHP SDK 语法高亮、自动补全，但又 **不想把 vendor 或 lock 提交**。

做法

```
cd laybot-php-sdk
composer install          # 本地生成 vendor
```

• `.gitignore` 已忽略 vendor/ & composer.lock → 不会进 Git 版本库  
• IDE (PhpStorm/VSC) 可解析依赖，提示消失  
• CI 可以同样先 `composer install` 再跑单测  
• 打包发布时 Packagist 只看源代码，不受 vendor 影响

================================================================
6. 复制流程到其它语言
----------------------------------------------------------------  

| 语言 | 子目录 | 公共仓库 | 管理器 | 推送命令示例 |
|------|--------|----------|--------|--------------|
| Node | laybot-node-sdk | ai-sdk-js | npm | `npm publish` |
| Python | laybot-python-sdk | ai-sdk-py | PyPI | `python -m build && twine upload dist/*` |
| Go | laybot-go-sdk | ai-sdk-go | go modules | 直接 tag |

拆分命令仍然：

```bash
git subtree split --prefix=laybot-python-sdk -b py-split
git push -f py-sdk py-split:main
git tag v0.1.0 py-split && git push py-sdk v0.1.0
```

================================================================
7. 常用脚本 / 命令速查
----------------------------------------------------------------  

```bash
# 添加远端
git remote add php-sdk git@github.com:laybot/ai-sdk-php.git

# 生成分支
git subtree split --prefix=laybot-php-sdk -b php-split

# 推主分支 + tag
git push -f php-sdk php-split:main
git tag vX.Y.Z php-split
git push php-sdk vX.Y.Z

# 查看当前作者配置
git config user.name
git config user.email
```

可在母仓 **scripts/push-php.sh** 写：

```bash
#!/bin/bash
git subtree split --prefix=laybot-php-sdk -b php-split
git push -f php-sdk php-split:main
ver=$(jq -r .version laybot-php-sdk/composer.json)
git tag -f $ver php-split
git push php-sdk $ver
```

================================================================  
FAQ
----------------------------------------------------------------  

1. **为什么不提交 composer.lock？**  
   对 **library** 而言应让下游选择依赖版本，官方建议不提交 lock。  
   对 **应用**（如 demo-site）再提交 lock 防止漂移。

2. **vendor 会不会上传到 GitHub？**  
   `.gitignore` 层层忽略即可：
   ```
   laybot-php-sdk/vendor/
   laybot-php-sdk/composer.lock
   ```

3. **需要删除历史再发布？**  
   公开 tag 后不要改指针；问题可通过新 tag 解决。

4. **IDE 自动补全报错？**  
   只在本机 `composer install`，不影响版本库。

================================================================  
至此，你的「母仓 + 多 SDK 子目录 + GitHub + Packagist」流水线已经搭建完成，并兼顾 IDE 开发体验与发布规范。照此指北操作即可长期、稳定地维护所有语言的 LayBot SDK。