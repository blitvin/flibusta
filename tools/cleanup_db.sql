delete from libgenrelist where not exists (select 1 from libgenre where libgenrelist.genreid = libgenre.genreid);
delete from libseqname where not exists ( select 1 from libseq where libseqname.seqid = libseq.seqid);
-- Bookless authors are intentionally KEPT (addbook module offers them as
-- candidate authors for locally added books). Regular library search/browse
-- filters them out at query level instead.
