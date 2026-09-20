# JSON Schema Draft 2020-12 metaschema

The official Draft 2020-12 metaschema and the vocabulary metaschemas it
references, published by the JSON Schema organisation and copied here verbatim
from <https://json-schema.org/draft/2020-12/>.

They are bundled so that `SubmissionSchema` can validate a tool's input schema
before the work is durably enqueued **without retrieving anything over the
network**. The validator resolves these URIs from disk and nothing else, so a
queued request can never cause an outbound fetch.

`meta/format-assertion` is deliberately absent: the root metaschema does not
reference it, and this package does not enable the format-assertion vocabulary.

Update these files only by copying a newer official release verbatim.
