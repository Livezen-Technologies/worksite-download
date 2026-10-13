# The tools scripts/publish-linux-repos.sh needs (worksuite-desktop#351; docs/linux-repositories.md): reprepro,
# createrepo_c, gpg, dpkg-deb, rpm and flock. No key is built in; it comes from the environment at run time.
#
#   docker build -f docker/linux-repo.Dockerfile -t worksuite-linux-repo .
#   docker run --rm -v /srv/linux-repos:/srv/linux-repos -v "$PWD/incoming:/incoming:ro" \
#     -e WORKSUITE_REPO_GPG_PRIVATE_KEY -e WORKSUITE_REPO_GPG_PASSPHRASE \
#     worksuite-linux-repo --channel stable --root /srv/linux-repos /incoming/WorkSuite-0.2.249-amd64.deb
FROM debian:bookworm-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends reprepro createrepo-c gnupg gpgv dpkg-dev rpm util-linux ca-certificates \
 && rm -rf /var/lib/apt/lists/*
COPY scripts/publish-linux-repos.sh scripts/linux-repo-sign.sh /opt/linux-repo/
ENTRYPOINT ["/opt/linux-repo/publish-linux-repos.sh"]
