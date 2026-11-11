# Posts and timelines

A post is text on this instance. Federation is a side effect of writing one,
not a second kind of post.

## Writing

`POST /api/v1/posts` accepts:

| Field | Required | Rule |
| --- | --- | --- |
| `content` | yes | trimmed, 1–5000 characters after trimming |
| `content_warning` | no | trimmed; an empty string means "none"; at most 200 characters |
| `visibility` | no | `public` (default), `unlisted`, `followers`, `direct` |

Validation lives in `PostService::validate()` and returns one message per
field, which the API exposes as `error.fields`.

`direct` is refused with 422: a direct post needs recipients, and recipients
are part of a follow graph that only tracks remote followers so far. Refusing
is honest; sending an empty audience to the network is not.

## Visibility

| Visibility | Public timeline | Home timeline | Single post |
| --- | --- | --- | --- |
| `public` | yes | yes | anyone |
| `unlisted` | yes | yes | anyone |
| `followers` | no | author only | author only (until local follows exist) |
| `direct` | no | author only | author only |

The single-post rule is enforced by `PostService::getVisible()`: a non-public
post answers 404 to anyone but its author. `unlisted` is publicly readable by
design — it is only hidden from timelines.

## Timelines

`GET /api/v1/timelines/public` returns `public` and `unlisted` posts, newest
first, with the id as the tie-break. `GET /api/v1/timelines/home` returns the
caller's own posts plus everything publicly visible. Neither is a follow-based
timeline yet: that needs local follows, which the core does not model.

Both accept `limit` (default 20, clamped to 1–40) and `before`, an exclusive
canonical timestamp cursor. A cursor that is not a timestamp is a 400
(`invalid_cursor`) rather than "ignore and start over", because a client that
silently receives page one again would loop forever.

## Deleting

`DELETE /api/v1/posts/{id}` soft-deletes: the row keeps `deleted_at`, every
read filters it, and a `Delete` activity is queued for the audience. Keeping
the row means a retried `Delete` still has a stable object id to refer to.
