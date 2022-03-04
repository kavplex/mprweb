from unittest import mock

import pytest

from aurweb import config, db, logging, time
from aurweb.models import Package, PackageBase, PackageComment, User
from aurweb.models.account_type import USER_ID
from aurweb.scripts import rendercomment
from aurweb.scripts.rendercomment import update_comment_render
from aurweb.testing.git import GitRepository

logger = logging.get_logger(__name__)
aur_location = config.get("options", "aur_location")


@pytest.fixture(autouse=True)
def setup(db_test, git: GitRepository):
    config_get = config.get

    def mock_config_get(section: str, key: str) -> str:
        if section == "serve" and key == "repo-path":
            return git.file_lock.path
        elif section == "options" and key == "commit_uri":
            return "/cgit/aur.git/log/?h=%s&id=%s"
        return config_get(section, key)

    with mock.patch("aurweb.config.get", side_effect=mock_config_get):
        yield


@pytest.fixture
def user() -> User:
    with db.begin():
        user = db.create(
            User,
            Username="test",
            Email="test@example.org",
            Passwd=str(),
            AccountTypeID=USER_ID,
        )
    yield user


@pytest.fixture
def pkgbase(user: User) -> PackageBase:
    now = time.utcnow()
    with db.begin():
        pkgbase = db.create(
            PackageBase,
            Packager=user,
            Name="pkgbase_0",
            SubmittedTS=now,
            ModifiedTS=now,
        )
    yield pkgbase


@pytest.fixture
def package(pkgbase: PackageBase) -> Package:
    with db.begin():
        package = db.create(
            Package, PackageBase=pkgbase, Name=pkgbase.Name, Version="1.0"
        )
    yield package


def create_comment(
    user: User, pkgbase: PackageBase, comments: str, render: bool = True
):
    with db.begin():
        comment = db.create(
            PackageComment, User=user, PackageBase=pkgbase, Comments=comments
        )
    if render:
        update_comment_render(comment)
    return comment


def test_comment_rendering(user: User, pkgbase: PackageBase):
    text = "Hello world! This is a comment."
    comment = create_comment(user, pkgbase, text)
    expected = f"<p>{text}</p>"
    assert comment.RenderedComment == expected


def test_rendercomment_main(user: User, pkgbase: PackageBase):
    text = "Hello world! This is a comment."
    comment = create_comment(user, pkgbase, text, False)

    args = ["aurweb-rendercomment", str(comment.ID)]
    with mock.patch("sys.argv", args):
        rendercomment.main()
    db.refresh(comment)

    expected = f"<p>{text}</p>"
    assert comment.RenderedComment == expected


def test_markdown_conversion(user: User, pkgbase: PackageBase):
    text = "*Hello* [world](https://aur.archlinux.org)!"
    comment = create_comment(user, pkgbase, text)
    expected = "<p><em>Hello</em> " '<a href="https://aur.archlinux.org">world</a>!</p>'
    assert comment.RenderedComment == expected


def test_html_sanitization(user: User, pkgbase: PackageBase):
    text = '<script>alert("XSS!")</script>'
    comment = create_comment(user, pkgbase, text)
    expected = '&lt;script&gt;alert("XSS!")&lt;/script&gt;'
    assert comment.RenderedComment == expected


def test_link_conversion(user: User, pkgbase: PackageBase):
    text = """\
Visit https://www.archlinux.org/#_test_.
Visit *https://www.archlinux.org/*.
Visit <https://www.archlinux.org/>.
Visit `https://www.archlinux.org/`.
Visit [Arch Linux](https://www.archlinux.org/).
Visit [Arch Linux][arch].
[arch]: https://www.archlinux.org/\
"""
    comment = create_comment(user, pkgbase, text)
    expected = """\
<p>Visit <a href="https://www.archlinux.org/#_test_">\
https://www.archlinux.org/#_test_</a>.
Visit <em><a href="https://www.archlinux.org/">https://www.archlinux.org/</a></em>.
Visit <a href="https://www.archlinux.org/">https://www.archlinux.org/</a>.
Visit <code>https://www.archlinux.org/</code>.
Visit <a href="https://www.archlinux.org/">Arch Linux</a>.
Visit <a href="https://www.archlinux.org/">Arch Linux</a>.</p>\
"""
    assert comment.RenderedComment == expected


def test_git_commit_link(git: GitRepository, user: User, package: Package):
    commit_hash = git.commit(package, "Initial commit.")
    logger.info(f"Created commit: {commit_hash}")
    logger.info(f"Short hash: {commit_hash[:7]}")

    text = f"""\
{commit_hash}
{commit_hash[:7]}
x.{commit_hash}.x
{commit_hash}x
0123456789abcdef
`{commit_hash}`
http://example.com/{commit_hash}\
"""
    comment = create_comment(user, package.PackageBase, text)

    pkgname = package.PackageBase.Name
    cgit_path = f"/cgit/aur.git/log/?h={pkgname}&amp;"
    expected = f"""\
<p><a href="{cgit_path}id={commit_hash[:12]}">{commit_hash[:12]}</a>
<a href="{cgit_path}id={commit_hash[:7]}">{commit_hash[:7]}</a>
x.<a href="{cgit_path}id={commit_hash[:12]}">{commit_hash[:12]}</a>.x
{commit_hash}x
0123456789abcdef
<code>{commit_hash}</code>
<a href="http://example.com/{commit_hash}">\
http://example.com/{commit_hash}\
</a>\
</p>\
"""
    assert comment.RenderedComment == expected


def test_flyspray_issue_link(user: User, pkgbase: PackageBase):
    text = """\
FS#1234567.
*FS#1234*
FS#
XFS#1
`FS#1234`
https://archlinux.org/?test=FS#1234\
"""
    comment = create_comment(user, pkgbase, text)

    expected = """\
<p><a href="https://bugs.archlinux.org/task/1234567">FS#1234567</a>.
<em><a href="https://bugs.archlinux.org/task/1234">FS#1234</a></em>
FS#
XFS#1
<code>FS#1234</code>
<a href="https://archlinux.org/?test=FS#1234">\
https://archlinux.org/?test=FS#1234\
</a>\
</p>\
"""
    assert comment.RenderedComment == expected


def test_lower_headings(user: User, pkgbase: PackageBase):
    text = """\
# One
## Two
### Three
#### Four
##### Five
###### Six\
"""
    comment = create_comment(user, pkgbase, text)

    expected = """\
<h5>One</h5>
<h6>Two</h6>
<h6>Three</h6>
<h6>Four</h6>
<h6>Five</h6>
<h6>Six</h6>\
"""
    assert comment.RenderedComment == expected
