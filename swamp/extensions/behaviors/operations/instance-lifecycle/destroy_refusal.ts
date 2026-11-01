export class DestroyRefusal extends Error {
  constructor(readonly code:string){super(code);}
}
