#include<bits/stdc++.h>
using namespace std;

typedef pair<int, int> Edge;

int primMST(int V, vector<vector<pair<int , int>>>& adj)
{
   vector<double> key (V,DBL_MAX);
   vector<int> parent (V,-1);
   vector<bool> inMST (V, false);
   priority_queue<Edge , vector<Edge> , greater<Edge>> pq;

   key[0] = 0;
   pq.push({0,0});

   double Product = 0;

   while(!pq.empty()){
     int u = pq.top().second;
     pq.pop();

     if(inMST[u])
        continue;
     inMST[u] = true;
     Product += key[u];

     for(auto[v,w]:adj[u]){
        double weight = log(w);
        if(!inMST[v] && weight < key[v]){
            key[v] = weight;
            parent[v] = u;
            pq.push({key[v],v});
        }

     }

   }

   cout << "Edges: \n ";
   double p = 1;
   for(int v = 1; v < V; v++){
    cout << parent[v] << "-" << v << endl;
    p *= exp(key[v]);
 }

 cout << "Minimum Product: " <<(long long)round(p) << endl;

}


int main()
{

    int n,m;
    cin>> n;
    cin>>m;
    vector<vector<pair<int,int>>> adj(n);

    for(int i =0; i<m; i++){
        int u,v,w;
        cin>> u;
        cin>>v;
        cin>>w;
        adj[u].push_back({v,w});
        adj[v].push_back({u,w});
    }

    primMST(n,adj);

    return 0;
}
