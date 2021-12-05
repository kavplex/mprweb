#!/usr/bin/env python3

from datetime import datetime

from sqlalchemy import and_

from aurweb import db
from aurweb.models import PackageBase


def _main():
    # One day behind.
    limit_to = int(datetime.utcnow().timestamp()) - 86400

    query = db.query(PackageBase).filter(
        and_(PackageBase.SubmittedTS < limit_to,
             PackageBase.PackagerUID.is_(None)))
    db.delete_all(query)


def main():
    db.get_engine()
    with db.begin():
        _main()


if __name__ == '__main__':
    main()
