List of unimplemented features/bug fixes

1. ~~Fix delay in service when calling an action ( pushing the button)~~
2. ~~Fix issue 51 in zlsl/flibusta - Out of memory for big files  : use streams to read files from zip archive and read by chunk~~
3. ~~Fix books_zip table generation - overlaps in zip contents exist . This should also allow daily updates incorporation without additional user actions~~
4. Fix bookshelf 

Features

~~1. Add new module OPDS for help with setting up feed in OPDS readers~~
~~2. User management and authentication , access control to the library~~
~~3. github action for automatic dockerhub publication when main branch is updated~~
4. Module for adding new books to the library ( including correct work after DB recreation)
5. Incremental updates of DB from import files
6. Clean up and update documentation, reference to dockerhub images repository for the project, service section
7. Fallback to retreiving required book from flibusta
